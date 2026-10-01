import os
import sys

import numpy as np

sys.path.insert(0, os.path.dirname(os.path.dirname(__file__)))
import gpu_acceleration


def test_cpu_mode_never_probes_or_changes_pixels(monkeypatch):
    gpu_acceleration.configure("cpu")
    monkeypatch.setattr(gpu_acceleration, "_cupy",
                        lambda: (_ for _ in ()).throw(AssertionError("CUDA probed")))
    source = np.zeros((1000, 1000, 3), dtype=np.float32)
    yy, xx = np.mgrid[:1000, :1000].astype(np.float32)
    assert gpu_acceleration.resample(source, xx, yy) is None


def test_unavailable_gpu_reports_cpu_fallback(monkeypatch):
    gpu_acceleration.configure("auto")
    monkeypatch.setattr(gpu_acceleration, "_cupy", lambda: None)
    monkeypatch.setattr(gpu_acceleration, "_ERROR", "test driver error")
    result = gpu_acceleration.status()
    assert result["available"] is False
    assert "CPU fallback" in result["label"]


def test_small_preview_stays_on_cpu(monkeypatch):
    gpu_acceleration.configure("auto")
    monkeypatch.setattr(gpu_acceleration, "_cupy",
                        lambda: (_ for _ in ()).throw(AssertionError("CUDA probed")))
    source = np.zeros((32, 32, 3), dtype=np.float32)
    yy, xx = np.mgrid[:32, :32].astype(np.float32)
    assert gpu_acceleration.resample(source, xx, yy) is None
