"""Run native COLD SNAP parity in a dedicated Windows process job.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import argparse
import json
import os
from pathlib import Path
import subprocess
import time

import win32api
import win32con
import win32event
import win32job
import win32process


def run(executable, output, timeout=90, *, source=None):
    executable = Path(executable).resolve(strict=True)
    output = Path(output).resolve()
    output.mkdir(parents=True, exist_ok=True)
    cleanup_path = output / "process-cleanup.json"
    mutex = win32event.CreateMutex(None, False, "Local\\SnapSmackColdSnapParity")
    if win32event.WaitForSingleObject(mutex, 0) not in (win32con.WAIT_OBJECT_0, win32con.WAIT_ABANDONED):
        mutex.Close()
        raise RuntimeError("Another COLD SNAP parity run is active.")

    job = win32job.CreateJobObject(None, "")
    limits = win32job.QueryInformationJobObject(job, win32job.JobObjectExtendedLimitInformation)
    limits["BasicLimitInformation"]["LimitFlags"] |= win32job.JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
    win32job.SetInformationJobObject(job, win32job.JobObjectExtendedLimitInformation, limits)
    process = thread = None
    assigned = False
    cleanup = {}
    try:
        environment = dict(os.environ)
        environment.update(
            SNAPSMACK_PARITY_RUNNER="1",
            QT_QPA_PLATFORM="offscreen",
            QTWEBENGINE_DISABLE_SANDBOX="1",
            QTWEBENGINE_CHROMIUM_FLAGS="--disable-gpu",
        )
        entry = [str(Path(source).resolve(strict=True))] if source else []
        command = subprocess.list2cmdline([str(executable), *entry, "--parity-capture", str(output)])
        startup = win32process.STARTUPINFO()
        process, thread, pid, _ = win32process.CreateProcess(
            str(executable), command, None, None, False,
            win32con.CREATE_SUSPENDED | win32con.CREATE_NO_WINDOW | win32con.CREATE_UNICODE_ENVIRONMENT,
            environment, str(executable.parent), startup,
        )
        win32job.AssignProcessToJobObject(job, process)
        assigned = True
        win32process.ResumeThread(thread)
        outcome = win32event.WaitForSingleObject(process, int(timeout * 1000))
        if outcome != win32con.WAIT_OBJECT_0:
            cleanup.update(root_pid=pid, timeout=True)
            win32job.TerminateJobObject(job, 1)
        else:
            cleanup.update(root_pid=pid, root_exit=win32process.GetExitCodeProcess(process))

        deadline = time.monotonic() + 10
        while True:
            accounting = win32job.QueryInformationJobObject(job, win32job.JobObjectBasicAccountingInformation)
            active = accounting["ActiveProcesses"]
            if not active or time.monotonic() >= deadline:
                break
            time.sleep(0.1)
        cleanup.update(total_processes=accounting["TotalProcesses"], active_after_grace=active)
        if active:
            win32job.TerminateJobObject(job, 1)
        cleanup["pass"] = cleanup.get("root_exit") == 0 and active == 0
        return 0 if cleanup["pass"] else 1
    finally:
        if process is not None and not assigned:
            win32api.TerminateProcess(process, 1)
        job.Close()
        if thread is not None:
            thread.Close()
        if process is not None:
            process.Close()
        win32event.ReleaseMutex(mutex)
        mutex.Close()
        cleanup_path.write_text(json.dumps(cleanup, indent=2) + "\n", encoding="utf-8")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("executable")
    parser.add_argument("output")
    parser.add_argument('--source', help='Run a source launcher with the supplied Python executable')
    args = parser.parse_args()
    raise SystemExit(run(args.executable, args.output, source=args.source))

# ===== SNAPSMACK EOF =====
