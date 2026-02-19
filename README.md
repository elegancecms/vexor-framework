# ⚡ Vexor Framework

**Vexor** — PHP 8.2+ için geliştirilmiş, performans ve güvenlik odaklı, enterprise-grade MVC + Service Layer framework.

> Laravel'in tüm gücü, fazladan ağırlık olmadan.

---

## 🏗️ Mimari

```
Hybrid MVC + Service Layer
├── Controller  → HTTP işlemlerini karşılar
├── Service     → İş mantığı burada yaşar
├── Repository  → Veri erişim katmanı
└── Model       → Veritabanı temsili (ActiveRecord)
```

## 📁 Dizin Yapısı

```
vexor/
├── app/
│   ├── Controllers/     # HTTP Controller'ları
│   ├── Models/          # ORM Model'ları
│   ├── Services/        # İş mantığı servisleri
│   ├── Middleware/      # HTTP Middleware'leri
│   └── Commands/        # CLI komutları
├── config/
│   ├── app.php          # Uygulama konfigürasyonu
│   ├── database.php     # Veritabanı konfigürasyonu
│   └── auth.php         # Kimlik doğrulama konfigürasyonu
├── database/
│   ├── migrations/      # Veritabanı migration'ları
│   └── seeds/           # Test verisi
├── public/
│   └── index.php        # HTTP giriş noktası
├── routes/
│   ├── web.php          # Web route'ları (CSRF korumalı)
│   └── api.php          # API route'ları (JWT/API Key)
├── src/
│   └── Core/            # Framework çekirdeği
├── storage/
│   ├── logs/            # Log dosyaları
│   └── cache/           # Cache dosyaları
└── vexor                # CLI aracı
```

---

## 🚀 Kurulum

```bash
# Composer bağımlılıklarını yükle
composer install

# .env dosyasını oluştur
cp .env.example .env

# Uygulama anahtarı oluştur
php vexor key:generate

# Geliştirme sunucusunu başlat
php vexor serve
```

---

## 🛣️ Router

```php
// Temel route'lar
$router->get('/users', 'UserController@index');
$router->post('/users', 'UserController@store');
$router->put('/users/{id}', 'UserController@update');
$router->delete('/users/{id}', 'UserController@destroy');

// Closure route
$router->get('/ping', function(Request $request): Response {
    return Response::json(['pong' => true]);
});

// Named route
$router->get('/profile', 'ProfileController@show')->name('profile');

// Route grubu
$router->prefix('/admin')->middleware('auth')->group(function($r) {
    $r->get('/dashboard', 'AdminController@dashboard');
});

// RESTful resource (tam CRUD)
$router->resource('posts', PostController::class);

// API resource (sadece index, show, store, update, destroy)
$router->apiResource('posts', PostController::class);
```

---

## 🎮 Controller

```php
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        // Input alma (XSS korumalı)
        $search = $request->safe('search');
        
        return $this->json(['users' => []]);
    }

    public function store(Request $request): Response
    {
        // Validation
        $data = $this->validate($request, [
            'name'     => 'required|min:2|max:100',
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $data['password'] = $this->security()->hashPassword($data['password']);
        $user = User::create($data);

        return $this->success($user->toArray(), 'User created.', 201);
    }
}
```

---

## 🗄️ ORM / QueryBuilder

```php
// Fluent QueryBuilder — hazırlanmış ifadeler, SQL injection imkansız
$users = User::query()
    ->where('is_active', true)
    ->whereLike('name', '%john%')
    ->whereIn('role', ['admin', 'editor'])
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();

// ActiveRecord
$user = User::find(1);
$user->name = 'Jane';
$user->save();

// İlişkiler
class Post extends Model {
    public function author() { return $this->belongsTo(User::class, 'user_id'); }
    public function comments() { return $this->hasMany(Comment::class, 'post_id'); }
}

// Toplu işlemler
User::where('is_active', false)->delete();

// Transaction
DB::transaction(function ($db) {
    $db->table('accounts')->where('id', 1)->decrement('balance', 100);
    $db->table('accounts')->where('id', 2)->increment('balance', 100);
});

// Sayfalama
$users = User::query()->paginate(15, $page);
// ['data' => [...], 'total' => 100, 'per_page' => 15, 'last_page' => 7]
```

---

## 🔐 Güvenlik

### CSRF Koruması
```php
// View'da
<?= csrf_field() ?>
<?= security()->csrfMeta() ?>

// Manuel kontrol
security()->validateCsrf($request);
```

### XSS Koruması
```php
// Output encoding
e($user->name)                   // HTML context
security()->escape($val, 'js')   // JS context
security()->purify($html)        // HTML sanitize
```

### Rate Limiting
```php
// Route bazlı
$router->middleware('RateLimitMiddleware')->group(...);

// Manuel
if (!security()->rateLimit('login:' . $ip, 5, 60)) {
    abort(429, 'Too many attempts.');
}
```

### Şifreleme
```php
$encrypted = security()->encrypt('gizli veri');
$decrypted = security()->decrypt($encrypted);
```

### Argon2id ile Şifre
```php
$hash = security()->hashPassword('password123');
$ok   = security()->verifyPassword('password123', $hash);
```

---

## 🔑 Authentication

### Session Auth (Web)
```php
// Giriş
$success = auth()->attempt(['email' => 'a@b.com', 'password' => '...']);

// Oturumu kapat
auth()->logout();

// Mevcut kullanıcı
$user = auth()->user();
if (auth()->check()) { ... }
```

### JWT Auth (API)
```php
// Token oluştur
$token = auth()->generateJwt($user, ttl: 3600);

// İstemci: Authorization: Bearer {token}
// Vexor otomatik doğrular
```

### API Key
```php
// Key oluştur
$key = security()->generateApiKey('myapp');

// İstemci: X-API-Key: {key} veya ?api_key={key}
// Vexor otomatik doğrular
```

---

## 📱 2FA (TOTP)

```php
// Secret oluştur
$secret = security()->generate2FASecret();

// Kod doğrula (Google Authenticator vb.)
$valid = security()->verifyTotp($secret, $inputCode);
```

---

## ⚡ CLI (Vexor Konsol)

```bash
# Tüm komutları gör
php vexor

# Model oluştur
php vexor make:model Post --migration

# Controller oluştur
php vexor make:controller PostController --api

# Middleware oluştur
php vexor make:middleware RoleMiddleware

# Migration oluştur
php vexor make:migration create_posts_table

# Migration çalıştır
php vexor migrate

# Route listesi
php vexor route:list

# Geliştirme sunucusu
php vexor serve --port=9000

# Cache temizle
php vexor cache:clear
```

### Özel Komut Yazma

```php
class SendEmailsCommand extends Command
{
    public function getName(): string        { return 'emails:send'; }
    public function getDescription(): string { return 'Send pending emails'; }

    public function execute(Input $input, Console $console): int
    {
        $limit = $input->option('limit', 100);
        $this->info("Sending up to {$limit} emails...");

        // İş mantığı burada
        $this->success('Done!');
        return 0;
    }
}
```

---

## 🧩 Middleware

```php
class RoleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->getAttribute('user');

        if (!$user?->isAdmin()) {
            return Response::json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
```

---

## 🛡️ Güvenlik Özellikleri Özeti

| Özellik | Durum |
|---|---|
| CSRF Token | ✅ Her POST/PUT/PATCH/DELETE |
| XSS Output Encoding | ✅ Çok bağlamlı (html/js/url/css) |
| SQL Injection | ✅ Sadece Prepared Statements |
| SQL Injection Tespiti | ✅ Pattern matching + engelleme |
| Rate Limiting | ✅ Token bucket, IP bazlı |
| Argon2id Şifreleme | ✅ Otomatik rehash |
| AES-256-GCM Şifreleme | ✅ GCM Auth Tag ile |
| JWT Auth | ✅ HS256, exp kontrolü |
| API Key | ✅ SHA-256 hashlenmiş saklama |
| 2FA TOTP | ✅ RFC 6238 uyumlu |
| Güvenli Cookie | ✅ HttpOnly, Secure, SameSite=Strict |
| Güvenli Session | ✅ Regenerate, Strict SameSite |
| HTTP Güvenlik Başlıkları | ✅ CSP, HSTS, X-Frame vb. |
| Hata Gizleme (Prod) | ✅ Detay sadece debug modda |
| Güvenlik Logu | ✅ Tüm ihlaller kayıt altında |

---

## ⚙️ Gereksinimler

- PHP 8.2+
- ext-pdo, ext-json, ext-mbstring, ext-openssl
- Composer

---

*Vexor Framework — Hız ve güvenlik, ödün vermeden.*
# vexor-framework
