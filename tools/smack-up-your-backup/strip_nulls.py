# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.


import os

for f in os.listdir('.'):
    if f.endswith('.py'):
        data = open(f, 'rb').read()
        clean = data.rstrip(b'\x00')
        if len(clean) < len(data):
            open(f, 'wb').write(clean)
            print(f'Stripped {len(data) - len(clean)} null bytes from {f}')
        else:
            print(f'OK: {f}')

print('Done.')
# ===== SNAPSMACK EOF =====
