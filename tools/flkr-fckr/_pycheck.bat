@echo off
cd /d "C:\dev\snapsmack\tools\flkr-fckr"
echo === python -m py_compile === > "_pycheck.out" 2>&1
python -m py_compile main.py >> "_pycheck.out" 2>&1
echo PYEXIT=%errorlevel%>> "_pycheck.out"
echo === py -3 -m py_compile === >> "_pycheck.out" 2>&1
py -3 -m py_compile main.py >> "_pycheck.out" 2>&1
echo PYEXIT_PY=%errorlevel%>> "_pycheck.out"
echo DONE>> "_pycheck.out"
