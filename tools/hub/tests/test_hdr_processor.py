"""Luminance HDR stays external and receives a safe, explicit recipe."""

import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
HUB = os.path.dirname(HERE)
if HUB not in sys.path:
    sys.path.insert(0, HUB)

import hdr_processor


def test_build_command_outputs_hdr_master_and_16_bit_tiff(tmp_path):
    inputs = [tmp_path / "under exposed.tif", tmp_path / "over exposed.tif"]
    command = hdr_processor.build_command(
        str(tmp_path / "luminance-hdr-cli.exe"), [str(path) for path in inputs],
        str(tmp_path / "master.exr"), str(tmp_path / "edit.tif"),
        align="AIS", deghost=.35, model="debevec", operator="mantiuk06",
        saturation=1.2, detail=.8)
    assert command[0].endswith("luminance-hdr-cli.exe")
    assert command[command.index("--ldrTiff") + 1] == "16b"
    assert command[command.index("--save") + 1].endswith("master.exr")
    assert command[command.index("--output") + 1].endswith("edit.tif")
    assert command[command.index("--autoag") + 1] == "0.35"
    assert command[-2:] == [os.path.abspath(str(path)) for path in inputs]


def test_build_command_rejects_unrecognised_options(tmp_path):
    try:
        hdr_processor.build_command("tool", ["one", "two"], "master.exr", "edit.tif",
                                    operator="not-a-real-operator")
    except ValueError as error:
        assert "operator" in str(error)
    else:
        raise AssertionError("unrecognised CLI values must not be passed through")


def test_output_names_are_filesystem_safe(tmp_path):
    master, edit = hdr_processor.output_paths(str(tmp_path), "IMG: 42 / bracket")
    assert master.endswith("IMG__42___bracket-master.exr")
    assert edit.endswith("IMG__42___bracket-16bit.tif")

