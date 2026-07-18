import re
import os

with open('../170full_qiita.md', 'r', encoding='utf-8') as f:
    content = f.read()

sync_blocks = re.findall(r'<!-- SYNC: (.*?) -->\s*```.*?\n(.*?)\n```', content, re.DOTALL)
for filename, code in sync_blocks:
    # ensure dir exists
    if '/' in filename:
        os.makedirs(os.path.dirname(filename), exist_ok=True)
    with open(filename, 'w', encoding='utf-8') as f:
        f.write(code)
    print(f"Written {filename}")
