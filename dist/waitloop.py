import subprocess, sys, time
login, pwd = sys.argv[1], sys.argv[2]
args=[r'C:\Users\Andrew\AppData\Local\Programs\Python\Python313\python.exe','send.py','-u',login,'-p',pwd,'-security_journal','1','-add_version','1','-edit_module','1','-set_stable','1']
out=''
for i in range(45):
    r=subprocess.run(args,capture_output=True,cwd=r'D:\git\awz.seo\tools\build')
    out=(r.stdout+r.stderr).decode('cp1251',errors='replace')
    with open(r'D:\git\awz.seo\dist\wait_last.txt','w',encoding='utf8') as f: f.write(out)
    pending = ('очереди на проверке' in out) or ('отправлен на проверку' in out) or ('Альфа' in out and 'доступен только после' in out)
    print('attempt', i+1, 'pending' if pending else 'final')
    sys.stdout.flush()
    if not pending: break
    time.sleep(60)
print('FINAL_OUTPUT_BELOW')
print(out)
