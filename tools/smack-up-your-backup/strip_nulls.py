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
