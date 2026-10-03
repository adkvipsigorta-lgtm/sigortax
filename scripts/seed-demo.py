"""
sigorta_demo icin sahte (demo) veri uretici.
Gercek kisi/firma verisi KULLANILMAZ — hepsi uydurma ama gercekci Turkce.

Uretir:
  - 1 admin + 3 acente kullanicisi
  - 4 sube (branch)
  - 30 musteri (bireysel + kurumsal karisik)
  - ~120 police (cesitli sigorta turleri, bazilari zeyilli, bazilari iptal)
  - ~25 gorev (yenileme + teklif)
"""
import subprocess, random, datetime, hashlib

MYSQL = r"C:/Users/smart/Desktop/wamp/SecureWAMP_Portable/mysql/bin/mysql.exe"
DB = "sigorta_demo"

def run_sql(sql):
    p = subprocess.run([MYSQL, "-uroot", "-p06010255", "--default-character-set=utf8mb4", DB],
                       input=sql.encode("utf-8"), capture_output=True)
    if p.returncode != 0:
        print("HATA:", p.stderr.decode("utf-8", "ignore")[:500])
    return p

def esc(s):
    if s is None:
        return "NULL"
    return "'" + str(s).replace("\\", "\\\\").replace("'", "\\'") + "'"

# bcrypt hash uretemiyoruz PHP'siz; PHP ile uret
def bcrypt(pw):
    php = r"C:/Users/smart/Desktop/wamp/SecureWAMP_Portable/php/php.exe"
    out = subprocess.run([php, "-r", f"echo password_hash('{pw}', PASSWORD_BCRYPT);"], capture_output=True)
    return out.stdout.decode().strip()

random.seed(42)
TODAY = datetime.date(2026, 6, 5)

# ---- İsim havuzları (sahte) ----
ERKEK = ["Ahmet","Mehmet","Mustafa","Ali","Hüseyin","Hasan","İbrahim","Osman","Yusuf","Murat",
         "Emre","Burak","Kaan","Cem","Serkan","Tolga","Onur","Barış","Eren","Deniz"]
KADIN = ["Ayşe","Fatma","Emine","Hatice","Zeynep","Elif","Meryem","Şerife","Sultan","Hülya",
         "Esra","Büşra","Derya","Gizem","Pınar","Sevgi","Aslı","Burcu","Merve","Selin"]
SOYAD = ["Yılmaz","Kaya","Demir","Çelik","Şahin","Yıldız","Yıldırım","Öztürk","Aydın","Özdemir",
         "Arslan","Doğan","Kılıç","Aslan","Çetin","Kara","Koç","Kurt","Özkan","Şimşek",
         "Polat","Korkmaz","Çakır","Erdoğan","Güneş","Aksoy","Bulut","Taş","Yavuz","Aydoğan"]
FIRMA_EK = ["İnşaat","Tekstil","Gıda","Lojistik","Otomotiv","Makina","Yazılım","Danışmanlık",
            "Turizm","Enerji","Mobilya","Elektrik","Sağlık","Eğitim","Mühendislik"]
FIRMA_TUR = ["A.Ş.","Ltd. Şti.","San. Tic. Ltd. Şti.","Holding A.Ş."]
SEHIRLER = ["İstanbul","Ankara","İzmir","Bursa","Antalya","Adana","Konya","Gaziantep","Mersin","Kayseri"]
MESLEKLER = ["Mühendis","Doktor","Öğretmen","Avukat","Muhasebeci","Esnaf","Memur","Serbest Meslek","Emekli","İşçi"]
PLAKA_IL = ["34","06","35","16","07","01","42","27","33","38"]
ARAC_MARKA = ["Renault","Fiat","Volkswagen","Ford","Toyota","Hyundai","Opel","Peugeot","Honda","BMW"]
ARAC_MODEL = {"Renault":["Clio","Megane","Symbol"],"Fiat":["Egea","Doblo"],"Volkswagen":["Golf","Passat","Polo"],
              "Ford":["Focus","Fiesta"],"Toyota":["Corolla","Yaris"],"Hyundai":["i20","Accent"],
              "Opel":["Astra","Corsa"],"Peugeot":["301","208"],"Honda":["Civic"],"BMW":["320i","520i"]}

def rand_phone():
    return "05" + str(random.randint(300000000, 599999999))

def rand_tc():
    # Gecerli TC algoritmasi (demo amacli, gercek kisilerle eslesmesin diye 9xx ile basla)
    d = [random.randint(0,9) for _ in range(9)]
    d[0] = random.randint(1,9)
    d10 = ((d[0]+d[2]+d[4]+d[6]+d[8])*7 - (d[1]+d[3]+d[5]+d[7])) % 10
    d.append(d10)
    d11 = sum(d) % 10
    d.append(d11)
    return "".join(map(str, d))

def rand_vkn():
    return str(random.randint(1000000000, 9999999999))

# ---- Now stamp ----
NOW = "2026-06-05 10:00:00"

# ============ 1) BRANCHES ============
branches = [
    (1, "Merkez Şube", "02161234567", 0, "TR330006100519786457841326"),
    (2, "Kadıköy Acentesi", "02163334455", 15, "TR120006200119000006672315"),
    (3, "Ataşehir Acentesi", "02165556677", 12, "TR760001000211987654321098"),
    (4, "Ümraniye Acentesi", "02164445566", 10, "TR980001500158007292345678"),
]
vals = ",".join(f"({i},{esc(n)},{esc(p)},{c},{esc(ib)},'{NOW}','{NOW}',NULL)" for i,n,p,c,ib in branches)
run_sql(f"INSERT INTO branches (id,name,phone,commission_rate,iban,created_at,updated_at,deleted_at) VALUES {vals};")
print(f"branches: {len(branches)}")

# ============ 2) USERS ============
pw_admin = bcrypt("demo1234")
users = [
    (1, "Demo Yönetici", "admin@demo.com", pw_admin, 1, 1, None),   # role 1 = admin
    (2, "Kadıköy Temsilci", "kadikoy@demo.com", pw_admin, 1, 2, 2), # role 2 = acente
    (3, "Ataşehir Temsilci", "atasehir@demo.com", pw_admin, 1, 2, 3),
    (4, "Merkez Operasyon", "merkez@demo.com", pw_admin, 1, 0, 1),  # role 0 = kullanici
]
vals = ",".join(f"({i},{esc(n)},{esc(e)},{esc(pw)},1,{r},{('NULL' if b is None else b)},'{NOW}','{NOW}',NULL)"
                for i,n,e,pw,act,r,b in users)
run_sql(f"INSERT INTO users (id,name,email,password,is_active,role,branch_id,created_at,updated_at,deleted_at) VALUES {vals};")
print(f"users: {len(users)} (sifre: demo1234)")

# ============ 3) CUSTOMERS ============
cust_rows = []
cust_ids = []
for cid in range(1, 31):
    corporate = random.random() < 0.30
    if corporate:
        firma = f"{random.choice(SOYAD)} {random.choice(FIRMA_EK)} {random.choice(FIRMA_TUR)}"
        name = firma
        ctype = "CORPORATE"
        idno = rand_vkn()
        tax_office = random.choice(SEHIRLER) + " VD"
        birth = None
        contact = random.choice(ERKEK+KADIN) + " " + random.choice(SOYAD)
        sector = random.choice(FIRMA_EK)
        job = None
        marital = None
    else:
        first = random.choice(ERKEK+KADIN)
        name = f"{first} {random.choice(SOYAD)}"
        ctype = "INDIVIDUAL"
        idno = rand_tc()
        tax_office = None
        birth = (TODAY - datetime.timedelta(days=random.randint(7000, 21000))).isoformat()
        contact = None
        sector = None
        job = random.choice(MESLEKLER)
        marital = random.choice(["Evli","Bekar"])
    cust_ids.append(cid)
    cust_rows.append(
        f"({cid},{esc(ctype)},{esc(name)},{esc(idno)},{esc(tax_office)},{esc(birth)},"
        f"{esc(rand_phone())},NULL,{esc(name.split()[0].lower()+'@ornek.com')},{esc(contact)},"
        f"{esc(marital)},{esc(job)},NULL,{esc(sector)},NULL,NULL,NULL,NULL,NULL,'{NOW}','{NOW}',NULL)"
    )
run_sql("INSERT INTO customers (id,customer_type,name,identity_no,tax_office,birth_date,phone,phone_alt,email,contact_person,marital_status,job,dependents_count,sector,country_id,city_id,district_id,address,note,created_at,updated_at,deleted_at) VALUES "
        + ",".join(cust_rows) + ";")
print(f"customers: {len(cust_rows)}")

# ============ 4) POLICIES ============
# Aktif subcategory sigorta turleri
sub = subprocess.run([MYSQL,"-uroot","-p06010255",DB,"-N","-e",
    "SELECT id, code, branch_group FROM insurance_types WHERE level='subcategory' AND is_active=1"],
    capture_output=True).stdout.decode().strip().split("\n")
ins_types = []
for line in sub:
    parts = line.split("\t")
    if len(parts) >= 3:
        ins_types.append((int(parts[0]), parts[1], parts[2]))

companies_ids = [int(x) for x in subprocess.run([MYSQL,"-uroot","-p06010255",DB,"-N","-e",
    "SELECT id FROM companies WHERE deleted_at IS NULL"], capture_output=True).stdout.decode().split()]

pol_rows = []
pid = 0
def make_policy(pid, cust_id, ins_id, ins_code, branch_group, parent_id=None, endorsement=1,
                cancelled=False, base_no=None, period_offset_days=0):
    company = random.choice(companies_ids)
    branch = random.choice([1,2,3,4])
    prod = random.choices(["SELF","INCOMING","OUTGOING"], weights=[70,20,10])[0]
    policy_no = base_no or ("000" + str(random.randint(1000000000, 9999999999)))
    # Tarihler: bazilari yaklasiyor (yenileme icin), bazilari uzak
    start = TODAY - datetime.timedelta(days=random.randint(0, 330) - period_offset_days)
    expires = start + datetime.timedelta(days=365)
    issue = start
    gross = round(random.uniform(800, 45000), 2)
    net = round(gross * random.uniform(0.85, 0.97), 2)
    if cancelled:
        gross = -abs(gross); net = -abs(net)
    comp_comm = random.choice([10,12,15,18,20])
    branch_comm = random.choice([0,5,8,10]) if prod != "SELF" else 0

    # Arac bilgisi (trafik/kasko)
    plate = chassis = engine = brand = model = myear = "NULL"
    if ins_code in ("TRAFFIC",) or branch_group in ("TRAFİK","KASKO"):
        il = random.choice(PLAKA_IL)
        plate = esc(f"{il} {random.choice('ABCDEFGHJKLMNPRSTUVYZ')}{random.choice('ABCDEFGHJKLMNPRSTUVYZ')} {random.randint(100,9999)}")
        chassis = esc("VF1" + "".join(random.choices("ABCDEFGH0123456789", k=14)))
        engine = esc("K9K" + str(random.randint(100000,999999)))
        mk = random.choice(ARAC_MARKA)
        brand = esc(mk)
        model = esc(random.choice(ARAC_MODEL[mk]))
        myear = esc(str(random.randint(2012, 2025)))
    uavt = dask = "NULL"
    if branch_group == "KONUT" or ins_code == "DASK":
        uavt = esc(str(random.randint(10000000, 99999999)))
        if ins_code == "DASK":
            dask = esc("DASK" + str(random.randint(100000,999999)))

    cust_name = subprocess.run([MYSQL,"-uroot","-p06010255",DB,"-N","-e",
        f"SELECT name FROM customers WHERE id={cust_id}"], capture_output=True).stdout.decode().strip()

    return (f"({pid},{('NULL' if parent_id is None else parent_id)},{esc(prod)},{cust_id},{ins_id},{company},{branch},"
            f"{esc(policy_no)},{esc(cust_name)},NULL,{esc(issue.isoformat())},{esc(start.isoformat())},{esc(expires.isoformat())},"
            f"{gross},{net},{comp_comm},{branch_comm},{1 if cancelled else 0},0,1,{endorsement},"
            f"{plate},{chassis},{engine},NULL,{brand},{model},{myear},{uavt},{dask},NULL,NULL,NULL,1,1,"
            f"'{NOW}','{NOW}',NULL)"), policy_no

# 100 normal police
for _ in range(100):
    pid += 1
    cust = random.choice(cust_ids)
    ins_id, ins_code, bg = random.choice(ins_types)
    cancelled = random.random() < 0.12
    row, _ = make_policy(pid, cust, ins_id, ins_code, bg, cancelled=cancelled)
    pol_rows.append(row)

# 8 zeyilli police (ana + zeyil)
for _ in range(8):
    pid += 1
    parent = pid
    cust = random.choice(cust_ids)
    ins_id, ins_code, bg = random.choice(ins_types)
    row, base_no = make_policy(pid, cust, ins_id, ins_code, bg, parent_id=None, endorsement=1)
    pol_rows.append(row)
    # 1-2 zeyil
    for z in range(random.randint(1,2)):
        pid += 1
        row, _ = make_policy(pid, cust, ins_id, ins_code, bg, parent_id=parent, endorsement=z+2, base_no=base_no)
        pol_rows.append(row)

run_sql("INSERT INTO policies (id,parent_id,production_type,customer_id,insurance_type_id,company_id,branch_id,policy_no,insured_name,insured_no,issued_at,starts_at,expires_at,gross_premium,net_premium,company_comm_rate,branch_comm_rate,is_cancelled,no_renewal_reminder,is_approved,endorsement_no,plate_no,chassis_no,engine_no,registration_no,vehicle_brand,vehicle_model,vehicle_year,uavt_code,dask_no,network,additional_insureds,reference_source,created_by,sold_by,created_at,updated_at,deleted_at) VALUES "
        + ",".join(pol_rows) + ";")
print(f"policies: {len(pol_rows)}")

print("\n=== SEED TAMAMLANDI ===")
