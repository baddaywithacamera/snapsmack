"""Resource-limited child processes for untrusted media decoders on Windows."""

from __future__ import annotations

import ctypes
from ctypes import wintypes
import os
import subprocess
import threading
import time


JOB_OBJECT_LIMIT_PROCESS_MEMORY = 0x00000100
JOB_OBJECT_LIMIT_JOB_MEMORY = 0x00000200
JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE = 0x00002000
JobObjectExtendedLimitInformation = 9


class IO_COUNTERS(ctypes.Structure):
    _fields_ = [("ReadOperationCount", ctypes.c_ulonglong),
                ("WriteOperationCount", ctypes.c_ulonglong),
                ("OtherOperationCount", ctypes.c_ulonglong),
                ("ReadTransferCount", ctypes.c_ulonglong),
                ("WriteTransferCount", ctypes.c_ulonglong),
                ("OtherTransferCount", ctypes.c_ulonglong)]


class JOBOBJECT_BASIC_LIMIT_INFORMATION(ctypes.Structure):
    _fields_ = [("PerProcessUserTimeLimit", ctypes.c_longlong),
                ("PerJobUserTimeLimit", ctypes.c_longlong),
                ("LimitFlags", wintypes.DWORD),
                ("MinimumWorkingSetSize", ctypes.c_size_t),
                ("MaximumWorkingSetSize", ctypes.c_size_t),
                ("ActiveProcessLimit", wintypes.DWORD),
                ("Affinity", ctypes.c_size_t),
                ("PriorityClass", wintypes.DWORD),
                ("SchedulingClass", wintypes.DWORD)]


class JOBOBJECT_EXTENDED_LIMIT_INFORMATION(ctypes.Structure):
    _fields_ = [("BasicLimitInformation", JOBOBJECT_BASIC_LIMIT_INFORMATION),
                ("IoInfo", IO_COUNTERS),
                ("ProcessMemoryLimit", ctypes.c_size_t),
                ("JobMemoryLimit", ctypes.c_size_t),
                ("PeakProcessMemoryUsed", ctypes.c_size_t),
                ("PeakJobMemoryUsed", ctypes.c_size_t)]


def _assign_windows_job(process, memory_bytes):
    kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
    kernel32.CreateJobObjectW.restype = wintypes.HANDLE
    job = kernel32.CreateJobObjectW(None, None)
    if not job:
        raise ctypes.WinError(ctypes.get_last_error())
    info = JOBOBJECT_EXTENDED_LIMIT_INFORMATION()
    info.BasicLimitInformation.LimitFlags = (
        JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE |
        JOB_OBJECT_LIMIT_PROCESS_MEMORY |
        JOB_OBJECT_LIMIT_JOB_MEMORY)
    info.ProcessMemoryLimit = int(memory_bytes)
    info.JobMemoryLimit = int(memory_bytes)
    if not kernel32.SetInformationJobObject(
            job, JobObjectExtendedLimitInformation, ctypes.byref(info), ctypes.sizeof(info)):
        kernel32.CloseHandle(job)
        raise ctypes.WinError(ctypes.get_last_error())
    if not kernel32.AssignProcessToJobObject(job, wintypes.HANDLE(process._handle)):
        kernel32.CloseHandle(job)
        raise ctypes.WinError(ctypes.get_last_error())
    return job, kernel32


def run(args, *, timeout, memory_bytes, **kwargs):
    """Run argv without a shell, terminating the whole job on timeout or close."""
    if kwargs.pop("capture_output", False):
        if "stdout" in kwargs or "stderr" in kwargs:
            raise ValueError("capture_output cannot be combined with stdout or stderr")
        kwargs["stdout"] = subprocess.PIPE
        kwargs["stderr"] = subprocess.PIPE
    kwargs["shell"] = False
    process = subprocess.Popen(args, **kwargs)
    job = kernel32 = None
    try:
        if os.name == "nt":
            job, kernel32 = _assign_windows_job(process, memory_bytes)
        stdout, stderr = process.communicate(timeout=timeout)
        return subprocess.CompletedProcess(args, process.returncode, stdout, stderr)
    except subprocess.TimeoutExpired:
        process.kill()
        process.communicate()
        raise
    finally:
        if job:
            kernel32.CloseHandle(job)


class Cancelled(Exception):
    """The caller asked for the child to stop before it finished."""


def stream(args, *, timeout, memory_bytes, on_line=None, should_cancel=None,
           poll_seconds=0.15, **kwargs):
    """Like run(), but hand stdout to the caller LINE BY LINE while it runs,
    and stop the child when the caller says to.

    WHY THIS EXISTS. run() uses communicate(), which returns nothing until the
    child exits. A local Generative Fill is thirty diffusion steps and several
    minutes of them, so with run() the window can only say "this can take
    several minutes" and the person has no way out but to kill SNAP SLAPPER.
    The child already prints a line per step; it just had nobody listening.

    ``on_line`` is called on a reader thread for each stdout line, stripped.
    ``should_cancel`` is polled about every ``poll_seconds``; when it returns
    true the child is killed and Cancelled is raised. Killing closes the job
    object, which takes any grandchildren with it -- the same guarantee run()
    gives on timeout.

    Returns a CompletedProcess whose stdout and stderr are the whole text, so a
    caller that does not care about progress reads it exactly like run().
    """
    if kwargs.pop("capture_output", False):
        raise ValueError("stream() always captures; do not pass capture_output")
    if "stdout" in kwargs or "stderr" in kwargs:
        raise ValueError("stream() owns stdout and stderr")
    kwargs["shell"] = False
    kwargs["stdout"] = subprocess.PIPE
    kwargs["stderr"] = subprocess.PIPE
    kwargs.setdefault("text", True)
    kwargs.setdefault("bufsize", 1)
    process = subprocess.Popen(args, **kwargs)
    job = kernel32 = None
    out_lines, err_lines = [], []

    def drain(pipe, sink, notify):
        try:
            for line in iter(pipe.readline, ""):
                sink.append(line)
                if notify:
                    try:
                        notify(line.rstrip())
                    except Exception:      # noqa: BLE001 - a bad callback must
                        pass               # not strand the child process
        finally:
            try:
                pipe.close()
            except Exception:              # noqa: BLE001
                pass

    readers = [threading.Thread(target=drain, args=(process.stdout, out_lines, on_line),
                                daemon=True),
               threading.Thread(target=drain, args=(process.stderr, err_lines, None),
                                daemon=True)]
    try:
        if os.name == "nt":
            job, kernel32 = _assign_windows_job(process, memory_bytes)
        for reader in readers:
            reader.start()
        deadline = time.monotonic() + timeout
        while True:
            if process.poll() is not None:
                break
            if should_cancel is not None and should_cancel():
                process.kill()
                process.wait()
                for reader in readers:
                    reader.join(timeout=2)
                raise Cancelled("Stopped before the model finished.")
            if time.monotonic() >= deadline:
                process.kill()
                process.wait()
                raise subprocess.TimeoutExpired(args, timeout)
            time.sleep(poll_seconds)
        for reader in readers:
            reader.join(timeout=5)
        return subprocess.CompletedProcess(
            args, process.returncode, "".join(out_lines), "".join(err_lines))
    finally:
        if process.poll() is None:
            process.kill()
        if job:
            kernel32.CloseHandle(job)


# ===== SNAPSMACK EOF =====
