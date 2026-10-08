# Maske
HERHANGİ BİR AÇIK VEYA BİR HATA VARSA BİLDİRİN = omeratas855@gmail.com

Anonim, gerçek zamanlı sohbet sitesi. Kullanıcılar kayıt olup giriş yapar, ancak sohbette herkes rastgele bir takma adla görünür. Gerçek kullanıcı adlarını yalnızca yönetici ve moderatörler görebilir.

PHP ve SQLite ile çalışır. Ayrı bir veritabanı sunucusu, Node.js veya derleme adımı gerekmez. Dosyaları PHP çalıştıran herhangi bir hosta yüklemek yeterlidir.

## İçindekiler

- [Özellikler](#özellikler)
- [Nasıl çalışır](#nasıl-çalışır)
- [Gereksinimler](#gereksinimler)
- [Kurulum](#kurulum)
- [Yapılandırma](#yapılandırma)
- [Veritabanının konumu ve yedekleme](#veritabanının-konumu-ve-yedekleme)
- [Roller ve yetkiler](#roller-ve-yetkiler)
- [Yönetim paneli](#yönetim-paneli)
- [Bakım modu](#bakım-modu)
- [Güvenlik](#güvenlik)
- [Güncelleme](#güncelleme)
- [Dosya yapısı](#dosya-yapısı)
- [API uç noktaları](#api-uç-noktaları)
- [Sorun giderme](#sorun-giderme)

## Özellikler

- Kayıt ve giriş sistemi
- Sohbette herkes anonim: her hesaba rastgele bir takma ad (`Anonim-4821` gibi) verilir ve renkli bir nokta ile gösterilir
- Sayfayı yenilemeden yeni mesajlar gelir
- Çevrimiçi kullanıcı sayısı
- Telefon ve masaüstü uyumlu arayüz
- Yönetici paneli: istatistikler, duyuru, kullanıcı ve mesaj yönetimi
- Süreli veya süresiz yasaklama (ban) ve susturma (mute)
- Moderatör rolü
- Bakım modu
- Yönetici hesabı bir yapılandırma dosyasından belirlenir

## Nasıl çalışır

Sunucu tarafı tek bir dosyadır (`api.php`). Tarayıcıdaki JavaScript (`app.js`) bu dosyaya JSON istekleri gönderir.

Yeni mesajlar WebSocket yerine kısa aralıklı sorgulama ile gelir: sekme açıkken her 1,5 saniyede, sekme arka plandayken her 5 saniyede bir sunucuya "yeni mesaj var mı" diye sorulur. Bu yüzden mesajlar anında değil, 1-2 saniye gecikmeyle görünür. Bunun karşılığında WebSocket desteği olmayan sıradan hostlarda da çalışır.

Giriş yapınca sunucu rastgele bir oturum anahtarı üretir. Tarayıcı bunu `localStorage` içinde saklar ve her istekte `X-Token` başlığıyla gönderir. Sunucu yalnızca anahtarın özetini (SHA-256) saklar. Oturumlar 30 gün geçerlidir.

### Anonimlik

- Sohbet ekranındaki mesajlarda sadece takma ad görünür.
- Mesajlar veritabanında kullanıcı numarasıyla birlikte saklanır, bu yüzden yönetici panelinde mesajın gerçek sahibi görülebilir.
- Moderatörler işlem yapabilmek için kullanıcı listesinde gerçek kullanıcı adlarını görür.
- Takma ad hesap açılırken verilir ve değişmez.

## Gereksinimler

- PHP 7.4 veya üstü
- `pdo_sqlite` PHP eklentisi (çoğu hostta açıktır)
- Veritabanı dosyası için yazılabilir bir klasör

## Kurulum

### Panelli hostlarda

1. Bu deponun içindekileri hostun web klasörüne yükleyin.
2. `env.example.php` dosyasını `env.php` adıyla kopyalayın.
3. `env.php` içindeki değerleri değiştirin (aşağıda açıklanıyor).
4. Siteyi tarayıcıda açın. Veritabanı ilk istekte kendiliğinden oluşur.
5. `env.php` içindeki yönetici kullanıcı adı ve şifresiyle giriş yapın. Sağ üstte "Yönetim" düğmesi görünür.

### Kendi bilgisayarınızda deneme

Proje klasöründe:

```
php -S localhost:8000
```

Sonra `http://localhost:8000` adresini açın. `php -m` çıktısında `pdo_sqlite` görünmelidir.

### Nginx

`.htaccess` dosyası yalnızca Apache'de çalışır. Nginx kullanıyorsanız site yapılandırmasına şu kuralı ekleyin:

```
location ^~ /data/ { deny all; }
```

Bunu ekleyemiyorsanız `DB_FILE` adını uzun ve tahmin edilmesi zor yapın ve veritabanını web klasörünün dışına koyun (bkz. [Veritabanının konumu ve yedekleme](#veritabanının-konumu-ve-yedekleme)).

## Yapılandırma

Tüm ayarlar `env.php` dosyasındadır. Bu dosya PHP olduğu için tarayıcıdan açılsa bile içeriği görünmez. `.gitignore` bu dosyayı depoya eklemez.

| Anahtar | Zorunlu | Açıklama |
| --- | --- | --- |
| `ADMIN_USER` | evet | Yönetici kullanıcı adı |
| `ADMIN_PASS` | evet | Yönetici şifresi |
| `DB_FILE` | evet | Veritabanı dosyasının adı (yol değil, sadece ad). Tahmin edilmesi zor bir ad seçin |
| `DB_DIR` | hayır | Veritabanının tutulacağı klasörün tam yolu |

Yönetici hesabı, her değer değiştiğinde otomatik güncellenir: `env.php` içindeki şifreyi değiştirip kaydettiğinizde yeni şifre bir sonraki istekte geçerli olur. `ADMIN_USER` değerini değiştirirseniz yeni adla yeni bir yönetici hesabı açılır, eskisi yönetici olarak kalır. Eskisini yönetim panelinden silemezsiniz, bu yüzden kullanıcı adını sonradan değiştirmemeniz önerilir.

## Veritabanının konumu ve yedekleme

Tüm hesaplar ve mesajlar tek bir SQLite dosyasında (`DB_FILE`) tutulur. Sunucu dosyayı şu sırayla arar ve ilk bulduğu yeri kullanır:

1. `DB_DIR` (tanımlıysa)
2. Web klasörünün bir üstündeki `maske-data` klasörü
3. Web klasörünün içindeki `data` klasörü
4. Web klasörünün kendisi

Dosya hiçbirinde yoksa, bu sırayla ilk yazılabilir klasörde oluşturulur. Veritabanı mümkünse web klasörünün dışında tutulduğu için tarayıcıdan indirilemez ve web dosyaları yenilendiğinde silinmez.

Yönetim paneli, Özet sekmesinde veritabanının tam yolunu gösterir.

**Yedek almak** için bu dosyayı dosya yöneticisinden indirmeniz yeterlidir. Geri yüklemek için aynı adla aynı klasöre koyun.

## Roller ve yetkiler

| İşlem | Kullanıcı | Moderatör | Yönetici |
| --- | --- | --- | --- |
| Sohbete yazmak | evet | evet | evet |
| Yasaklama ve yasak kaldırma | hayır | evet (sadece kullanıcılar) | evet |
| Susturma ve susturma kaldırma | hayır | evet (sadece kullanıcılar) | evet |
| Sohbetten atma | hayır | hayır | evet |
| Hesap oluşturma, silme, şifre sıfırlama | hayır | hayır | evet |
| Moderatör atama | hayır | hayır | evet |
| Mesaj silme, sohbeti temizleme | hayır | hayır | evet |
| Duyuru, bakım modu, istatistikler | hayır | hayır | evet |

Moderatörler yalnızca normal kullanıcılara işlem yapabilir. Yöneticilere ve diğer moderatörlere işlem yapılamaz. Kimse kendisine işlem yapamaz. Yetkiler yalnızca arayüzde gizlenmez, sunucuda da kontrol edilir.

## Yönetim paneli

Yönetici olarak giriş yapınca sohbetin üstünde "Yönetim" düğmesi görünür. Moderatörde aynı yerde "Moderasyon" yazar ve yalnızca kullanıcı listesi açılır.

**Özet**
- Çevrimiçi sayısı, toplam kullanıcı, son 24 saatte yeni kullanıcı, yasaklı ve susturulmuş sayıları, toplam mesaj ve son 24 saatteki mesaj
- Tüm sohbete duyuru gönderme
- Bakım modunu açma ve kapatma
- Tüm sohbeti silme
- Veritabanının konumu

**Kullanıcılar**
- Yeni hesap oluşturma (kullanıcı, moderatör veya yönetici)
- Yasaklama: dakika cinsinden süre sorulur, `0` süresiz demektir. Süre dolunca yasak kendiliğinden kalkar. Listede kalan süre görünür
- Susturma: aynı şekilde süreli veya süresiz. Susturulan kullanıcı sohbeti görür ama yazamaz
- Sohbetten atma: kullanıcının oturumları silinir, tekrar giriş yapabilir
- Şifre sıfırlama: kullanıcının tüm oturumları kapanır
- Hesap silme: hesapla birlikte kullanıcının tüm mesajları da silinir
- Moderatör yapma ve moderatörlükten alma

**Mesajlar**
- Son 200 mesajın gerçek kullanıcı adı, takma ad ve metniyle listesi
- Tek tek mesaj silme

## Bakım modu

Yönetim paneli, Özet sekmesinden açılır. İsteğe bağlı bir bakım mesajı yazabilirsiniz.

Bakım açıkken:
- Yönetici dışındaki herkes "Bakımdayız" ekranını görür. Sohbette olanlar da kısa süre içinde bu ekrana düşer.
- Yeni kayıt kapalıdır.
- Giriş yapan yönetici siteyi normal kullanır. Bakım ekranındaki "Yönetici girişi" düğmesi giriş formunu açar.
- Moderatörler de bakım sırasında giremez.

Bakım kapatılınca bakım ekranında bekleyenlerin sayfası kendiliğinden yenilenir.

## Güvenlik

- Şifreler PHP'nin `password_hash` fonksiyonuyla saklanır.
- Oturum anahtarları veritabanında yalnızca SHA-256 özetiyle tutulur.
- Tüm veritabanı sorguları hazır ifadelerle (prepared statement) yapılır.
- Mesajlar sayfaya `textContent` ile eklenir, bu yüzden mesajlar içinden HTML veya script çalıştırılamaz.
- Giriş ve kayıt denemeleri IP başına dakikada 10 ile sınırlıdır.
- Mesajlar en fazla 500 karakterdir ve bir kullanıcı en fazla 0,7 saniyede bir mesaj gönderebilir.
- Kullanıcı adları 3-20 karakter olmalı ve yalnızca harf, rakam ve `_` içerebilir. Şifreler en az 6 karakterdir.
- Sitenin HTTPS üzerinden yayınlanması önerilir, aksi halde şifreler ve oturum anahtarları şifrelenmeden gider.
- `env.php` dosyasını ve veritabanını hiçbir zaman depoya yüklemeyin. `.gitignore` bunları zaten dışarıda bırakır.

## Güncelleme

Yeni sürümde yalnızca şu dosyaları değiştirin:

- `api.php`
- `app.js`
- `index.html`
- `style.css`

`env.php` dosyasına ve veritabanının bulunduğu klasörlere (`data`, `maske-data`) dokunmayın. Veritabanı yapısı gerekirse ilk istekte kendiliğinden güncellenir, mevcut hesaplar ve mesajlar korunur.

Güncellemeden sonra eski sürüm görünüyorsa tarayıcı önbelleğini temizleyin veya siteyi gizli sekmede açın.

## Dosya yapısı

```
.
├── api.php            sunucu tarafı, tüm istekleri karşılar
├── app.js             tarayıcı tarafı
├── index.html         sayfa
├── style.css          tasarım
├── env.example.php    yapılandırma örneği
├── .htaccess          Apache için erişim kuralları
└── data/              yedek veritabanı klasörü
```

## API uç noktaları

Tüm istekler `api.php?r=<ad>` biçimindedir. Giriş yapılmış isteklerde `X-Token` başlığı gerekir.

| Ad | Yöntem | Yetki | Açıklama |
| --- | --- | --- | --- |
| `status` | GET | herkes | Bakım durumu |
| `register` | POST | herkes | Kayıt |
| `login` | POST | herkes | Giriş |
| `logout` | POST | oturum | Çıkış |
| `me` | GET | oturum | Kendi bilgilerim |
| `history` | GET | oturum | Son 100 mesaj |
| `poll` | GET | oturum | Yeni mesajlar, olaylar ve çevrimiçi sayısı |
| `send` | POST | oturum | Mesaj gönder |
| `admin/users` | GET | moderatör, yönetici | Kullanıcı listesi |
| `admin/ban`, `admin/unban`, `admin/mute`, `admin/unmute` | POST | moderatör, yönetici | Kullanıcı moderasyonu (`id` parametresi) |
| `admin/stats` | GET | yönetici | İstatistikler |
| `admin/messages` | GET | yönetici | Mesaj listesi |
| `admin/create` | POST | yönetici | Hesap oluştur |
| `admin/kick`, `admin/password`, `admin/remove`, `admin/mod`, `admin/unmod` | POST | yönetici | Kullanıcı yönetimi (`id` parametresi) |
| `admin/delmsg` | POST | yönetici | Mesaj sil (`id` parametresi) |
| `admin/clear` | POST | yönetici | Tüm sohbeti sil |
| `admin/announce` | POST | yönetici | Duyuru gönder |
| `admin/maint` | POST | yönetici | Bakım modunu aç veya kapat |

## Sorun giderme

**"Sunucu hatası" yazıyor.** Gerçek hatayı görmek için `api.php` dosyasının en altındaki `fail('Sunucu hatası', 500);` satırını geçici olarak `fail($e->getMessage(), 500);` yapın, hatayı okuyun ve sonra eski haline döndürün. Hata ayrıntıları herkese açık kalmamalıdır.

**`could not find driver`.** Hostta `pdo_sqlite` eklentisi kapalıdır. Hosttan açmasını isteyin veya PHP ayarlarından etkinleştirin.

**`unable to open database file`.** PHP, veritabanı klasörüne yazamıyor. Klasörü hosting panelinde yeniden oluşturun, izinlerini 775 yapın veya `env.php` içinde `DB_DIR` ile yazılabilir bir klasör gösterin.

**`env.php dosyası bulunamadı`.** `env.example.php` dosyasını `env.php` olarak kopyalamayı unutmuşsunuz.

**Değişiklikler görünmüyor.** Tarayıcı eski `app.js` dosyasını önbellekten gösteriyor olabilir. Önbelleği temizleyin veya gizli sekmede açın.

**Sunucu yeniden başlayınca veriler gidiyor.** Host, web klasörünü yeniden başlatmada sıfırlıyor olabilir. Özet sekmesindeki veritabanı yolunun web klasörünün dışında olduğundan emin olun ve düzenli yedek alın.

**Bakım modunda yönetici girişi yapılamıyor.** Bakım ekranındaki "Yönetici girişi" düğmesine basın. Şifreyi unuttuysanız `env.php` içindeki `ADMIN_PASS` değerini değiştirin, yeni şifre bir sonraki istekte geçerli olur.

**Giriş yapamıyorum, "Çok fazla deneme" yazıyor.** Giriş ve kayıt denemeleri IP başına dakikada 10 ile sınırlı. Bir dakika bekleyin.
