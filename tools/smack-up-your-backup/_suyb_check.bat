@echo off
cd /d "C:\dev\snapsmack\tools\smack-up-your-backup"
python -m py_compile sftp_client.py transport.py audit_engine.py backup_engine.py restore_engine.py main.py > "_suyb_check.out" 2>&1
echo EXIT [%errorlevel%] >> "_suyb_check.out"
echo DONE >> "_suyb_check.out"
