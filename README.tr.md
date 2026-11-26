# Easy Digital Downloads için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

Easy Digital Downloads için resmi Paymos ödeme ağ geçidi. Alıcı ödeme adımında Paymos'u seçer, Paymos'un barındırılan ödeme sayfasında stabil coin faturasını öder ve bekleyen siparişe geri döner. Sipariş, yalnızca imzalı bir webhook transferin zincir üstünde onaylandığını bildirdiğinde `complete` durumuna geçer — EDD indirme bağlantılarını bu durumda açar ve satın alma makbuzunu gönderir. Ödeme sayfasından mağazaya dönmek dosyayı tek başına açmaz.

[![İzleyin: 12 Resmi CMS Kripto Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)
> **Video anlatım (1:12, 6 dilde altyazı):** [YouTube'da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- WordPress 6.5 veya üzeri, 7.0 sürümüne kadar test edilmiştir — `paymos-easy-digital-downloads.php` eklenti üst bilgisindeki alt sınır
- Aynı üst bilgide zorunlu eklenti olarak tanımlanan Easy Digital Downloads. Bu ağ geçidi EDD 3.x sipariş modeline yazar
- OpenSSL destekli PHP 7.4 veya üzeri — kimlik bilgileri AES-256-GCM ile şifrelenir
- İnternetten erişilebilir bir HTTPS sitesi. Webhook kendi alan adınızda bir REST rotası olarak çalışır; bildirimler asla loopback, özel veya link-local adreslere gönderilmez
- Paymos panelinde bu mağazanın satış yaptığı projenin seçili olduğu bir Paymos hesabı

## Kurulum

`paymos-edd-<version>.zip` paketini [Releases](https://github.com/Paymos-labs/edd/releases/latest) sayfasından veya Paymos panelindeki **CMS integration** bölümünden indirin. WordPress'te **Plugins → Add New Plugin → Upload Plugin** (eski WordPress sürümlerinde bu ekran **Add New** olarak adlandırılır) üzerinden yükleyip etkinleştirin.

Paymos PHP SDK arşiv içinde hazır gelir; bu nedenle Composer adımı gerekmez. Her üye işyeri birebir aynı dosyayı indirir; paket içinde API anahtarı, API gizli anahtarı, proje kimliği, webhook gizli anahtarı, OAuth belirteci veya cihaz kodu bulunmaz.

## Bağlantı

Bağlantı kurmak için EDD'nin `manage_shop_settings` yetkisine (mağaza yöneticisi veya yönetici) sahip olmanız gerekir.

1. Paymos panelinde bu mağazanın satış yaptığı projeyi açın — onay sırasında doğrudan bu proje bağlanır ve sonradan ayrıca seçim yapılmaz.
2. **Downloads → Settings → Payments → Paymos** yolunu açın ve **Connect Paymos** düğmesine tıklayın.
3. Açılan sekmede site URL'sini ve projeyi onaylayın. Tarayıcı sekmeyi engellerse, bağlantı ve kullanıcı kodu düğmenin yanında görünür.
4. **Connection status** alanı otomatik olarak dolar: etkin mod, Sandbox ve Live yapılandırma durumu, maskelenmiş API anahtarı ve proje kimliği.

Bu tek onay hem Sandbox hem Live kimlik bilgilerini birlikte sunar. Tek etkin Payment anahtarınız yeniden kullanılır (yoksa yeni oluşturulur) ve aşağıdaki adreste bir Invoice webhook'u kaydedilir:

```text
https://your-store.example/wp-json/paymos-edd/v1/webhook
```

Mevcut bir webhook yalnızca geri bildirim URL'si, kategori ve proje birebir eşleştiğinde yeniden kullanılır.

Kimlik bilgileri otomatik yüklenmeyen (`non-autoloaded`) bir WordPress seçeneğinde, sitenin `auth` ve `secure_auth` salt değerlerinden türetilen anahtarla AES-256-GCM şifrelemesi altında saklanır. Kısa ömürlü yetkilendirme belirteci atılır ve sonraki tüm Merchant API istekleri HMAC ile imzalanır.

## Ayarlar

| Ayar | Varsayılan |
|---|---|
| Ödeme etiketi (Checkout label) | `Pay with stablecoins` |
| Mod (Mode) | Sandbox |
| Webhook URL | Salt okunur; bağlantı sırasında otomatik kaydedilir |
| Bağlantı durumu (Connection status) | Salt okunur |
| Hata ayıklama günlüğü (Debug logging) | Varsayılan olarak kapalı; açıldığında Paymos mesajlarını EDD hata ayıklama günlüğüne yazar |

Gizli anahtar giriş alanı veya proje seçici bulunmaz; her ikisi de onay sırasında otomatik sağlanır.

## İndirme ne zaman açılır

| Paymos olayı | EDD siparişi |
|---|---|
| Alıcı Paymos'u seçer, sipariş kaydedilir ve sepet boşaltılır | `pending`, fatura kimliğini içeren notla birlikte |
| `invoice.confirming` | `pending` — `"Paymos payment is confirming."` |
| `invoice.underpaid_waiting` | `pending`, notta kalan tutar ve token adı belirtilir |
| `invoice.awaiting_payment` | `pending`, notta onaylanmış bir transferin zincir üstünde geri alındığı belirtilir |
| `invoice.paid`, `invoice.paid_over` | `complete` — indirmeler açılır, makbuz gönderilir |
| `invoice.underpaid` | `failed` |
| `invoice.expired`, `invoice.cancelled` | `abandoned` (EDD'de hiç tamamlanmamış bekleyen siparişlerin durumu) |

Sipariş tamamlandığında zincir üstü işlem özeti (hash) EDD işlem kimliği olarak yazılır ve `_paymos_tx_hash` ile `_paymos_explorer_url` meta verileri kaydedilir. Zaten tamamlanmış bir siparişe gelen ikinci bir sonuç olayı tekrar tamamlama olarak tanınır ve mükerrer not eklenmez; sonradan gelen `confirming`, `underpaid`, `expired` veya `cancelled` olayları ise yoksayılarak hiçbir değişiklik yapılmaz.

Tutarı veya para birimi faturadan sapan bir sipariş hiçbir şekilde tamamlanmaz. `_paymos_amount_mismatch` meta anahtarı `yes` olarak işaretlenir, uyuşmazlık sipariş notlarına yazılır ve manuel inceleme gerekir. Bu süre boyunca dosya indirme kilitli kalır.

## Alıcı sonrasında ne görür

Satın alma makbuzuna ve sipariş makbuzu e-postasına bir **Payment confirmation** satırı eklenir: bildirimde blok gezgini bağlantısı varsa bu bağlantı, yoksa salt işlem özeti gösterilir. İşlem özeti yokken — tüm Sandbox siparişlerinde olduğu gibi — bu satır hiç gösterilmez.

## Canlıya geçmeden önce test edin

1. **Mode** ayarını Sandbox konumunda bırakın ve mağaza vitrininden bir dijital ürün satın alın.
2. Paymos paneli hâlâ Sandbox ortamındayken bu faturayı bulun ve **Pay Full**, **Pay 50%**, **Pay 150%** veya **Cancel** seçenekleriyle akışı test edin. Hiçbir gerçek fon hareket etmeden gerçek yaşam döngüsü olayları oluşur.
3. Siparişin durumunu, notlarını ve indirme bağlantısının açılıp açılmadığını kontrol edin.
4. **Mode** ayarını Live olarak değiştirin. İlk onay her iki ortamın kimlik bilgilerini de sağladığı için ikinci bir kurulum gerekmez.

## Webhook'lar

Rota WordPress REST API üzerinde kayıtlıdır ve yalnızca `POST` isteklerini kabul eder. `X-Webhook-Signature` her iki ortamın gizli anahtarıyla aynı anda doğrulanır, ardından yükteki `is_test` bayrağı doğrulamayı geçen ortamla karşılaştırılır — Live yükü bir Sandbox siparişini kapatamaz ve tersi de aynı katılıkla reddedilir. Daha önce görülmüş bir `event_id` onaylanıp atlanır. Sonuç olayları, herhangi bir veri yazılmadan önce Merchant API üzerinden tekrar sorgulanır ve siparişin kayıtlı projesi, tutarı ve para birimiyle karşılaştırılır.

Hatalarda uç nokta göndericinin işlem yapabileceği bir durum kodu döndürür; çünkü 2xx dışındaki yanıtlar olayı yeniden deneme kuyruğunda tutar: geçersiz imza veya süresi dolmuş zaman damgası için `401`, işleme hatasında `400`, kayıtlı webhook gizli anahtarı yoksa `500`. Yeniden deneme takvimi yaklaşık 16 saate yayılan 11 denemeden oluşur. Ödenmemiş faturalar imzalı `invoice.paid` webhook'larına dayanır ve yerel fatura mutabakat cron işi bulunmaz; bu nedenle tüm denemeleri aşan bir fatura olayı Paymos panelinden elle yeniden gönderilir. Bekleyen iadeler ise nihai duruma ulaşana kadar `paymos_edd_refunds_reconcile` WP-Cron göreviyle dakikada bir yerel olarak sorgulanır.

## İadeler

Ağ geçidi, Paymos Merchant API (`/v1/refunds`) üzerinden iki modda yerel zincir üstü iade destekler:

- `full` — ödenen fatura tutarının tamamını iade eder;
- `overpayment` — `invoice.paid_over` durumundaki siparişte yalnızca fazla ödenen tutarı iade eder ve siparişi `complete` durumunda tutar.

Fonlar yalnızca alıcının faturayı ödediği sırada Paymos tarafından kaydedilip doğrulanan `first_sender` cüzdan adresine geri gönderilir. Elle alıcı cüzdan adresi girilmez ve keyfi kısmi iade tutarları desteklenmez.

**Downloads → Orders** ekranında tamamlanmış bir Paymos siparişinde, EDD'nin yerel **Initiate a Refund** penceresinden (tüm sipariş kalemleri tam seçili olarak) `full` iade başlatabilir veya sipariş ayrıntı ekranındaki **Paymos refunds** panelinden hem `full` hem `overpayment` iadelerini ve durum kontrollerini yönetebilirsiniz. EDD yerel iade siparişini yalnızca Paymos `/wp-json/paymos-edd/v1/webhook` veya 1 dakikalık `paymos_edd_refunds_reconcile` WP-Cron görevi üzerinden `completed` durumunu doğruladıktan sonra kaydeder ve iade makbuzunu gönderir. Sandbox modunda, fon hareketi olmadan nihai iade sonuçları (`completed`, `failed`, `cancelled`) simüle edilebilir.

## Sorun giderme

**Alıcı ödeme yaptı ama dosya hâlâ kilitli.** Sipariş henüz ulaşmamış bir webhook'u bekliyor. Uç noktanın site dışından yanıt verdiğini doğrulayın — bir güvenlik eklentisi, HTTP Basic Auth veya "REST API'yi devre dışı bırak" ayarı bildirimleri sessizce engelleyebilir ve teslimatlar başka bir adrese yapılan yönlendirmeleri izlemez.

**Uç nokta `not_configured` yanıtı veriyor.** Kayıtlı kimlik bilgisi yoktur, dolayısıyla doğrulama yapılacak gizli anahtar bulunmaz. Ayarlar sayfasında **Connect Paymos** düğmesine basın ve onayı tamamlayın.

**`wp-config.php` düzenlendikten sonra her şey durdu.** Kimlik bilgisi zarfı `auth` ve `secure_auth` salt değerlerinden türetilen anahtarla şifrelenir. Bu değerleri değiştirmek veya veritabanını farklı salt değerlerine sahip bir siteye geri yüklemek zarfı okunamaz hâle getirir; yeni zarf almak için bağlantıyı yeniden kurun.

**Site yeni bir alan adına taşındıktan sonra hiçbir şey çalışmıyor.** Onaylanan site URL'si ve kayıtlı webhook hâlâ eski alan adına işaret ediyordur. Yeni alan adından bağlantıyı yeniden kurun.

## Bağlantılar

- Dokümantasyon: [paymos.io/tr/docs/cms-easy-digital-downloads](https://paymos.io/tr/docs/cms-easy-digital-downloads)
- Kaynak kodu ve sürümler: [Paymos-labs/edd](https://github.com/Paymos-labs/edd)
- Destek: [support@paymos.io](mailto:support@paymos.io)
