"""Typed artifact identity for SNAP SLAPPER projects.

An artifact path is data, never identity.  Producers and consumers exchange
stable IDs and validate kinds, which makes profiles impossible to open as
images and makes display proxies impossible to use as edit/export sources.
"""

from __future__ import annotations

from dataclasses import asdict, dataclass, field, replace
from enum import Enum
import hashlib
import json
import os
import uuid


class ArtifactKind(str, Enum):
    ORIGINAL_RAW = "original_raw"
    ORIGINAL_RASTER = "original_raster"
    RAW_PROFILE = "raw_profile"
    DEVELOPED_MASTER = "developed_master"
    DISPLAY_PROXY = "display_proxy"
    PROJECT_PAYLOAD = "project_payload"
    EXPORT_SOURCE = "export_source"


IMAGE_KINDS = {
    ArtifactKind.ORIGINAL_RAW, ArtifactKind.ORIGINAL_RASTER,
    ArtifactKind.DEVELOPED_MASTER, ArtifactKind.DISPLAY_PROXY,
    ArtifactKind.EXPORT_SOURCE,
}
EDIT_SOURCE_KINDS = {
    ArtifactKind.ORIGINAL_RASTER, ArtifactKind.DEVELOPED_MASTER,
}
EXPORT_SOURCE_KINDS = {
    ArtifactKind.ORIGINAL_RASTER, ArtifactKind.DEVELOPED_MASTER,
    ArtifactKind.EXPORT_SOURCE,
}


@dataclass(frozen=True)
class ArtifactRecord:
    id: str
    kind: ArtifactKind
    path: str
    source_refs: tuple[str, ...] = field(default_factory=tuple)
    content_hash: str = ""
    bit_depth: str = ""
    colorspace: str = ""
    producer_build: str = ""

    def value(self):
        value = asdict(self)
        value["kind"] = self.kind.value
        value["source_refs"] = list(self.source_refs)
        return value

    @classmethod
    def from_value(cls, value):
        return cls(id=str(value["id"]), kind=ArtifactKind(value["kind"]),
                   path=os.path.abspath(os.fspath(value["path"])),
                   source_refs=tuple(str(item) for item in value.get("source_refs", [])),
                   content_hash=str(value.get("content_hash", "")),
                   bit_depth=str(value.get("bit_depth", "")),
                   colorspace=str(value.get("colorspace", "")),
                   producer_build=str(value.get("producer_build", "")))


class ArtifactRegistry:
    def __init__(self, records=()):
        self._records = {}
        for record in records:
            self.add(record)

    def add(self, record):
        if not isinstance(record, ArtifactRecord):
            raise TypeError("artifact registry accepts ArtifactRecord values")
        if record.id in self._records and self._records[record.id] != record:
            raise ValueError(f"artifact ID is already assigned: {record.id}")
        for source_id in record.source_refs:
            if source_id not in self._records:
                raise ValueError(f"artifact source does not exist: {source_id}")
        self._records[record.id] = record
        return record.id

    @property
    def records(self):
        return tuple(self._records.values())

    def assign(self, kind, path, *, source_refs=(), content_hash="", bit_depth="",
               colorspace="", producer_build=""):
        record = ArtifactRecord(str(uuid.uuid4()), ArtifactKind(kind),
                                os.path.abspath(os.fspath(path)), tuple(source_refs),
                                content_hash, bit_depth, colorspace, producer_build)
        self.add(record)
        return record.id

    def derive(self, kind, path, *, source_refs, bit_depth="", colorspace="",
               producer_build=""):
        """Assign deterministic identity to a produced artifact's provenance."""
        for source_id in source_refs:
            if source_id not in self._records:
                raise ValueError(f"artifact source does not exist: {source_id}")
        identity = json.dumps({"kind": ArtifactKind(kind).value,
                               "source_refs": list(source_refs),
                               "producer_build": producer_build},
                              sort_keys=True, separators=(",", ":"))
        artifact_id = str(uuid.UUID(hashlib.sha256(identity.encode()).hexdigest()[:32]))
        record = ArtifactRecord(artifact_id, ArtifactKind(kind),
                                os.path.abspath(os.fspath(path)), tuple(source_refs), "",
                                bit_depth, colorspace, producer_build)
        self.add(record)
        return record.id

    def get(self, artifact_id, expected_kind=None):
        try:
            record = self._records[str(artifact_id)]
        except KeyError as error:
            raise KeyError(f"unknown artifact ID: {artifact_id}") from error
        if expected_kind is not None:
            accepted = ({ArtifactKind(expected_kind)} if isinstance(expected_kind, (str, ArtifactKind))
                        else {ArtifactKind(kind) for kind in expected_kind})
            if record.kind not in accepted:
                names = ", ".join(sorted(kind.value for kind in accepted))
                raise TypeError(f"artifact {record.id} is {record.kind.value}, expected {names}")
        return record

    def relocate(self, artifact_id, path):
        """Update storage location without changing identity or provenance."""
        record = self.get(artifact_id)
        self._records[record.id] = replace(record, path=os.path.abspath(os.fspath(path)))
        return self._records[record.id]

    def edit_source(self, artifact_id):
        return self.get(artifact_id, EDIT_SOURCE_KINDS)

    def export_source(self, artifact_id):
        return self.get(artifact_id, EXPORT_SOURCE_KINDS)

    def image(self, artifact_id):
        return self.get(artifact_id, IMAGE_KINDS)

    def value(self):
        return [record.value() for record in self._records.values()]

    @classmethod
    def from_value(cls, value):
        if not isinstance(value, list):
            raise ValueError("artifact registry must be a list")
        registry = cls()
        pending = [ArtifactRecord.from_value(item) for item in value]
        while pending:
            before = len(pending)
            for record in pending[:]:
                if all(source in registry._records for source in record.source_refs):
                    registry.add(record); pending.remove(record)
            if len(pending) == before:
                raise ValueError("artifact registry contains missing or cyclic source references")
        return registry


# ===== SNAPSMACK EOF =====
