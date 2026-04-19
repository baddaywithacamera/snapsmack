@echo off
echo Fix Your Batch Up — installing / checking dependencies...
pip install -r "%~dp0requirements.txt" --quiet

echo.
echo Launching Fix Your Batch Up...
python "%~dp0main.py"
pause
