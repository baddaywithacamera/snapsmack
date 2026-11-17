"""Independent checks of write capability, quota, anonymous credentials and file lifetime."""
# SNAPSMACK_EOF_HEADER: retain the canonical EOF marker at file end.
import io
import os
import pathlib
import sys
import tempfile
import unittest
from unittest import mock

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parents[1]))
import drive
from PIL import Image


class DriveReviewGuards(unittest.TestCase):
    def service(self, capability=True, limit='1000', usage='200'):
        service = mock.MagicMock()
        service.files.return_value.get.return_value.execute.return_value = {
            'name': 'Textures', 'mimeType': 'application/vnd.google-apps.folder',
            'capabilities': {'canAddChildren': capability}}
        service.about.return_value.get.return_value.execute.return_value = {
            'user': {'emailAddress': 'owner@example.test'},
            'storageQuota': {'limit': limit, 'usage': usage}}
        return service

    def test_visibility_alone_is_not_upload_permission(self):
        for capability in (False, None):
            service = self.service(capability)
            result = drive.probe_folder(service, 'folder')
            self.assertFalse(result['ok'])
            self.assertEqual(result['reason'], 'not_writable')
            service.files.return_value.create.assert_not_called()

    def test_account_and_storage_receipt_or_explicit_failure(self):
        result = drive.probe_folder(self.service(), 'folder')
        self.assertEqual(result['account'], 'owner@example.test')
        self.assertEqual(result['free_bytes'], 800)
        result = drive.probe_folder(self.service(usage='1000'), 'folder')
        self.assertEqual(result['reason'], 'quota')
        service = self.service()
        service.about.return_value.get.return_value.execute.side_effect = RuntimeError('refused')
        self.assertEqual(drive.probe_folder(service, 'folder')['reason'], 'account')

    def test_anonymous_session_disables_ambient_credentials(self):
        with mock.patch('requests.Session') as factory:
            session = factory.return_value.__enter__.return_value
            with drive._anonymous_get('https://drive.google.com/uc?id=test', timeout=5):
                self.assertFalse(session.trust_env)
            session.cookies.clear.assert_called_once()
            session.get.assert_called_once_with('https://drive.google.com/uc?id=test', timeout=5)

    def test_upload_releases_the_windows_source_handle(self):
        with tempfile.TemporaryDirectory() as directory:
            path = pathlib.Path(directory) / 'original.jpg'
            Image.new('RGB', (12, 19), 'blue').save(path)
            service = self.service()
            service.files.return_value.create.return_value.execute.return_value = {'id': 'new-only'}
            with mock.patch.object(drive, 'public_permission_receipt', return_value={'ok': True}), mock.patch.object(
                    drive, 'probe_public_download', return_value={'ok': True}):
                drive.upload(service, str(path), path.name)
            media = service.files.return_value.create.call_args.kwargs['media_body']
            self.assertTrue(media.stream().closed)
            path.rename(path.with_name('moved.jpg'))

    def test_qt_empty_queue_probe_runs_off_the_ui_thread(self):
        os.environ.setdefault('QT_QPA_PLATFORM', 'offscreen')
        from PySide6.QtWidgets import QApplication
        from PySide6.QtTest import QTest
        import sybu_qt
        app = QApplication.instance() or QApplication([])
        engine = mock.Mock(config={})
        engine.drive_check.return_value = {'ok': True, 'name': 'Textures', 'account': 'owner@example.test', 'free_bytes': 800}
        with mock.patch.object(sybu_qt.sybu_core, 'Engine', return_value=engine), mock.patch.object(
                sybu_qt.Window, '_load'), mock.patch.object(sybu_qt.QMessageBox, 'information') as message:
            window = sybu_qt.Window()
            window.timer.stop()
            window.drive_folder.setText('folder')
            window.drive_check_btn.click()
            self.assertEqual(window.table.rowCount(), 0)
            for _ in range(50):
                app.processEvents()
                if 'drive_check' not in window.pending:
                    break
                QTest.qWait(10)
            self.assertNotIn('drive_check', window.pending)
            self.assertTrue(window.drive_check_btn.isEnabled())
            engine.drive_check.assert_called_once_with('folder')
            self.assertIn('Textures', message.call_args.args[2])
            window.deleteLater()
            app.processEvents()


if __name__ == '__main__':
    unittest.main()
# ===== SNAPSMACK EOF =====
