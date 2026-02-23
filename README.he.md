# גשר WebMCP של GoldT - גשר WebMCP עבור WordPress

![WordPress Plugin Version](https://img.shields.io/badge/version-0.2.0-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)

גשר עבור 8 סוכני בינה מלאכותית (Claude, ChatGPT, Grok ועוד) דרך WebMCP עם OAuth 2.0

**מאובטח בתכנון:** זרימת קוד אימות OAuth 2.0 עם PKCE - אין סיסמאות מועברות דרך קווי תקשורת!

---

## 🚀 התחלה מהירה

### הַתקָנָה

1. העלה את התוסף אל `/wp-content/plugins/goldt-webmcp-bridge/`
2. הפעלה דרך מנהל מערכת של WordPress
3. **לפיתוח מקומי**: הפעל את השרת עם `php -S 0.0.0.0:8888 router.php` (ראה [הגדרת פיתוח](#-development-setup))

**זהו!** אין צורך בהגדרות.

**הערה:** התוסף כולל את כל התלויות הנדרשות:
- `firebase/php-jwt` (גרסה 6.10.0) - טיפול באסימוני JWT
- `predis/predis` (גרסה 3.4.0) - לקוח Redis להגבלת קצב (אופציונלי)

אין צורך בהתקנה ידנית של `קומפוסר` - הכל מגיע בחבילה!

---

## 🔐 מדריך אימות OAuth 2.0

### איך זה עובד

גשר WebMCP של GoldT משתמש ב- **OAuth 2.0 Authorization Code Flow עם PKCE** - הסטנדרט בתעשייה לאימות API מאובטח:

1. סוכן בינה מלאכותית מבקש אישור באמצעות אתגר קוד (PKCE)
2. המשתמש מאשר בדפדפן → מקבל קוד אישור חד פעמי
3. סוכן מחליף קוד עבור אסימון גישה (עם מאמת קוד)
4. הסוכן משתמש באסימון עבור קריאות API

**לקוחות רשומים מראש** (מוכנים לשימוש!):
- קלוד-איי - קלוד איי (אנתרופי)
- `chatgpt` - צ'אטGPT (OpenAI)
- תאומים - תאומים (גוגל)
- גרוק - גרוק (xAI)
- `מבוכה` - מבוכה בינה מלאכותית
- `copilot` - מיקרוסופט קופיילוט
- `meta-ai` - Meta AI (פייסבוק)
- `deepseek` - בינה מלאכותית של DeepSeek

---

### שלב 1: יצירת פרמטרים של PKCE

```bash
# Code verifier (random 128 character string)
CODE_VERIFIER=$(openssl rand -hex 64)

# Code challenge (SHA256 hash of verifier)
CODE_CHALLENGE=$(echo -n "$CODE_VERIFIER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')

# State (for CSRF protection)
STATE=$(openssl rand -hex 16)
```

---

### שלב 2: כתובת URL לאימות

הפנה את המשתמש לכתובת URL זו בדפדפן שלו:

```
http://yoursite.com/?goldtwmcp_oauth_authorize=1
  &response_type=code
  &client_id=claude-ai
  &redirect_uri=urn:ietf:wg:oauth:2.0:oob
  &scope=read%20write
  &state=YOUR_STATE
  &code_challenge=YOUR_CODE_CHALLENGE
  &code_challenge_method=S256
```

**היקף זמין:**
- `קריאה` - קריאה של פוסטים, דפים ותוכן
- `write` - יצירה ועדכון של פוסטים ודפים
- `delete` - מחיקת פוסטים ודפים
- `manage_users` - הצג ונהל חשבונות משתמשים

המשתמש יראה מסך הסכמה ויאשר. הוא יקבל **קוד אישור** (תקף ל-10 דקות).

---

### שלב 3: החלפת קוד עבור טוקן

**בַּקָשָׁה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "authorization_code",
    "client_id": "claude-ai",
    "code": "AUTHORIZATION_CODE_HERE",
    "redirect_uri": "urn:ietf:wg:oauth:2.0:oob",
    "code_verifier": "YOUR_CODE_VERIFIER"
  }'
```

**תְגוּבָה:**
```json
{
  "access_token": "wpc_c6c9f8398c5f7921713011d19676ee2f81470cf7ec7c71ce91925cd129853dd3",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "wpr_8a7b6c5d4e3f2a1b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f7a6b",
  "refresh_token_expires_in": 2592000,
  "scope": "read write"
}
```

⚠️ **הערות אבטחה:**
- קודי הרשאה הם **לשימוש חד פעמי** ותוקפם פג תוך 10 דקות
- אסימוני גישה פגים לאחר **שעה**
- אסימוני רענון פגים לאחר **30 יום**
- אימות PKCE מבטיח שרק הלקוח שיזם את הזרימה יוכל לתבוע את האסימון
- **שמור את אסימון הרענון שלך** - תצטרך אותו כדי לקבל אסמוני גישה חדשים מבלי לבצע אימות מחדש!

---

### שלב 4: שימוש ב-API

**בַּקָשָׁה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.searchPosts" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -d '{
    "search": "hello",
    "limit": 5
  }'
```

**תגובה:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "title": "Hello World",
      "content": "Welcome to WordPress...",
      "excerpt": "Welcome...",
      "author": {
        "id": "1",
        "name": "admin"
      },
      "date": "2024-01-15T10:30:00",
      "modified": "2024-01-15T10:30:00",
      "status": "publish",
      "url": "http://yoursite.com/hello-world",
      "categories": [],
      "tags": []
    }
  ]
}
```

---

### שלב 4: רענון אסימון הגישה (לאחר שעה)

אסימוני גישה פגים לאחר שעה. השתמש באסימון הרענון שלך כדי לקבל אסימון גישה חדש **מבלי** לבצע אימות מחדש:

**בַּקָשָׁה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "refresh_token",
    "client_id": "claude-ai",
    "refresh_token": "wpr_8a7b6c5d4e3f2a1b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f7a6b"
  }'
```

**תְגוּבָה:**
```json
{
  "access_token": "wpc_NEW_ACCESS_TOKEN_HERE",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "wpr_NEW_REFRESH_TOKEN_HERE",
  "refresh_token_expires_in": 2592000,
  "scope": "read write"
}
```

**חָשׁוּב:**
- אסימון הגישה הישן ואסימון הרענון **בוטלו אוטומטית**
- אתה מקבל אסימון גישה **חדש** וגם אסימון רענון
- אסימוני רענון תקפים למשך 30 יום
- אם תוקף אסימון הרענון פג, על המשתמש לאשר מחדש משלב 1

---

### שלב 5: ביטול אסימון (אופציונלי)

בטל אסימון גישה לאחר שתסיים או אם הוא נפרץ:

**בַּקָשָׁה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/revoke" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "wpc_c6c9f8398c5f7921713011d19676ee2f81470cf7ec7c71ce91925cd129853dd3"
  }'
```

**תְגוּבָה:**
```json
{
  "success": true,
  "message": "Token revoked successfully"
}
```

**הערה:** ביטול אסימון גישה מבטל גם את אסימון הרענון המשויך אליו.

---

## 🛠️ כלים זמינים

### 1. wordpress.searchפוסטים

חפש פוסטים בWordPress באמצעות פילטרים.

**פרמטרים:**
- `search` (מחרוזת, אופציונלי) - שאילתת חיפוש
- `קטגוריה` (מחרוזת, אופציונלי) - קטגוריית קטגוריה
- `tag` (מחרוזת, אופציונלי) - תגית
- `author` (מספר שלם, אופציונלי) - מזהה מחבר
- `status` (מחרוזת, אופציונלי) - סטטוס פרסום (ברירת מחדל: `publish`)
- `limit` (מספר שלם, אופציונלי) - מקסימום תוצאות (ברירת מחדל: 10)
- `offset` (מספר שלם, אופציונלי) - דילוג על תוצאות (ברירת מחדל: 0)

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.searchPosts" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "search": "technology",
    "category": "news",
    "limit": 10
  }'
```

---

### 2. wordpress.getPost

קבל פוסט בודד לפי מזהה או slug.

**פרמטרים:**
- `identifier` (מספר שלם|מחרוזת, נדרש) - מזהה פוסט או slug

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getPost" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier": 123}'
```

---

### 3. wordpress.searchPages

חפש דפי WordPress.

**פרמטרים:**
- `search` (מחרוזת, אופציונלי) - שאילתת חיפוש
- `parent` (מספר שלם, אופציונלי) - מזהה דף האב
- `status` (מחרוזת, אופציונלי) - סטטוס דף (ברירת מחדל: `publish`)
- `limit` (מספר שלם, אופציונלי) - מקסימום תוצאות (ברירת מחדל: 10)

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.searchPages" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"search": "about", "limit": 10}'
```

---

### 4. wordpress.getPage

קבל דף בודד לפי תעודת זהות או קוד זיהוי.

**פרמטרים:**
- `identifier` (מספר שלם|מחרוזת, נדרש) - מזהה דף או קוד זיהוי

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getPage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier": "about-us"}'
```

---

### 5. wordpress.getCurrentUser

קבל מידע על המשתמש המאומת.

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

**תגובה:**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "username": "admin",
    "email": "admin@example.com",
    "display_name": "Admin User",
    "roles": ["administrator"],
    "capabilities": ["edit_posts", "delete_posts", "manage_options", ...]
  }
}
```

---

## 🔐 בקרות ניהול

### תכונות אבטחה

נווט אל **מנהל WordPress → GoldT WebMCP → הגדרות** כדי לנהל את האבטחה:

#### 1. סובב את סוד JWT

**ניתוק חירום של כל סוכני הבינה המלאכותית:**
- לחץ על כפתור "סיבוב סוד JWT"
- כל אסימוני הגישה הקיימים הופכים ללא תקפים באופן מיידי.
- כל המשתמשים חייבים לאמת מחדש

**מתי להשתמש:**
- חשד לפריצת אבטחה
- פרטי גישה שאבדו/נגנבו
- שילוב פירוק

---

#### 2. חסימת גישת משתמש

**ביטול גישה עבור משתמשים ספציפיים:**
1. עבור אל **GoldT WebMCP ← הגדרות ← ניהול גישת משתמש**
2. הזן את מזהה המשתמש של WordPress
3. לחץ על "חסום משתמש"

**תוצאה:** המשתמש אינו יכול לאמת או להשתמש באסימונים קיימים, גם אם הם תקפים.

**כדי לשחזר גישה:** לחץ על "שחזר גישה" ליד משתמש חסום.

---

## 📊 הגבלת קצב

מגבלות ברירת מחדל (למשתמש):
- **50 בקשות לדקה**
- **1,000 בקשות לשעה**

**הגדר ב:** GoldT WebMCP → הגדרות

**תגובת מגבלת קצב:**
```json
{
  "code": "rate_limit_exceeded",
  "message": "Rate limit exceeded: 50 requests per minute",
  "data": {
    "status": 429,
    "retry_after": 45,
    "limit": 50,
    "current": 51
  }
}
```

---

## 🔐 שיטות עבודה מומלצות לאבטחה

### למנהלי האתר

1. **צור חשבונות משתמש ייעודיים לבינה מלאכותית** - אל תשתמש בחשבון המנהל שלך
2. **השתמש בסיסמאות אפליקציות** (WordPress 5.6+) - מאובטח יותר מסיסמאות רגילות
3. **מעקב אחר רשימת משתמשים חסומים** - בטל גישה כאשר אין עוד צורך בה
4. **הפעלת 2FA** - שכבת אבטחה נוספת (תוספים תואמים: Wordfence, iThemes Security)
5. **השתמש ב-HTTPS** - הצפן את כל התעבורה בסביבת הייצור

### למפתחים

1. **אחסן אישורים בצורה מאובטחת** - השתמש במשתני סביבה, לעולם לא בקוד קידוד
2. **טיפול יעיל בתוקף טוקנים** - יישום רענון אוטומטי
3. **כבדו את מגבלות הקצב** - שמרו תגובות במטמון במידת האפשר.
4. **השתמש בנקודות קצה של HTTPS** - לעולם אל תשלח אישורים דרך HTTP
5. **סובב אסימוני רענון** - קבל חדשים מעת לעת

---

## 🐛 פתרון בעיות

### שגיאות OAuth נפוצות

#### ``invalid_client`` - client_id לא חוקי
**פתרון:** השתמשו באחד מהלקוחות הרשומים מראש: `claude-ai`, `chatgpt`, או `gemini`

#### `"invalid_grant"` - קוד הרשאה לא תקין
**פתרון:**
- קודי הרשאה הם חד פעמיים ותוקפם פג לאחר 10 דקות
- בקשת קוד אישור חדש

#### אימות PKCE נכשל
**פתרון:** ודא שאתה משתמש באותו `code_verifier` שיצר את `code_challenge`

#### `"access_denied"` - משתמש חסום
**פתרון:** בדוק אם המשתמש נמצא ברשימה השחורה (GoldT WebMCP → OAuth Tokens)

#### `"פג תוקפו של האסימון"`
**פתרון:** תוקפם של טוקני גישה פג לאחר שעה. יש לבקש אישור חדש.

#### ``חרג ממגבלת התעריף``
**פתרון:**
- המתן לזמן ניסיון חוזר (סמן `retry_after` בתגובה)
- הגדלת מגבלות ב- **GoldT WebMCP → הגדרות**

#### שגיאות 404 ב-API של REST
**פתרון:**
1. עבור אל **הגדרות → קישורים קבועים**
2. לחץ על **שמור שינויים** (בטל את כללי הכתיבה מחדש)
3. בדוק שוב

---

## 🔧 הגדרת פיתוח

### פיתוח מקומי עם שרת PHP מובנה

**חשוב:** השרת המובנה של PHP דורש סקריפט נתב כדי שממשק ה-REST API של WordPress יפעל כראוי.

```bash
# Start server with router (REQUIRED for REST API)
cd /var/www/wp
php -S 0.0.0.0:8888 router.php
```

למה צריך `router.php`?
- שרת PHP מובנה אינו תומך בקישורים קבועים/כללים לכתיבה מחדש של WordPress כברירת מחדל.
- בלעדיו, בקשות ל-`/wp-json/...` מחזירות 404
- הנתב מעביר את כל הבקשות הלא סטטיות דרך `index.php` של WordPress

**ייצור:** ב-Apache/Nginx, הגדרות `.htaccess` או nginx מטפלות בזה באופן אוטומטי - `router.php` מיועד לפיתוח מקומי בלבד.

---

### בדיקת זרימת OAuth באופן מקומי

```bash
# 1. Generate PKCE parameters
CODE_VERIFIER=$(openssl rand -hex 64)
CODE_CHALLENGE=$(echo -n "$CODE_VERIFIER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')
STATE=$(openssl rand -hex 16)

# 2. Open authorization URL in browser (replace localhost:8888 with your server)
echo "http://localhost:8888/?goldtwmcp_oauth_authorize=1&response_type=code&client_id=claude-ai&redirect_uri=urn:ietf:wg:oauth:2.0:oob&scope=read%20write&state=$STATE&code_challenge=$CODE_CHALLENGE&code_challenge_method=S256"

# 3. After approval, exchange code for token
curl -X POST "http://localhost:8888/wp-json/goldt-webmcp-bridge/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d "{
    \"grant_type\": \"authorization_code\",
    \"client_id\": \"claude-ai\",
    \"code\": \"PASTE_CODE_HERE\",
    \"redirect_uri\": \"urn:ietf:wg:oauth:2.0:oob\",
    \"code_verifier\": \"$CODE_VERIFIER\"
  }"

# 4. Test API with token
curl -X POST "http://localhost:8888/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer YOUR_ACCESS_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

---

### הפעלת בדיקות

```bash
cd wp-content/plugins/goldt-webmcp-bridge
./tests/test-endpoints.sh
```

זה בודק:
- כל נקודות הקצה של הכלים
- אימות אסימון Bearer של OAuth 2.0
- נקודות קצה ציבוריות לעומת נקודות קצה מוגנות
- טיפול בשגיאות

---

### הוסף כלים מותאמים אישית

```php
add_action('goldtwmcp_register_modules', function($goldtwmcp_plugin) {
    $manifest = $goldtwmcp_plugin->get_manifest_instance();
    
    $manifest->register_tool('mysite.customTool', [
        'description' => 'My custom tool',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'param1' => [
                    'type' => 'string',
                    'description' => 'First parameter'
                ]
            ],
            'required' => ['param1']
        ]
    ]);
});
```

---

## 📋 יומן שינויים

### גרסה 0.2.0 - 22-02-2026 🔐 **שינוי פורץ דרך**

**אבטחה:** עברנו משם משתמש/סיסמה לזרימת קוד אימות OAuth 2.0 עם PKCE

* **נוסף:** זרימת קוד אימות OAuth 2.0 עם PKCE (S256)
* **נוספו:** לקוחות OAuth רשומים מראש (claude-ai, chatgpt, gemini)
* **נוסף:** מסך הסכמה של OAuth לאימות משתמש
* **נוסף:** ממשק משתמש לניהול אסימוני OAuth (GoldT WebMCP → אסימוני OAuth)
* **נוסף:** `router.php` לתמיכה מובנית בשרת PHP
* **הוסר:** נקודת קצה ישירה לאימות שם משתמש/סיסמה (`/auth/login`)
* **אבטחה:** קודי הרשאה הם חד פעמיים ותוקףם 10 דקות
* **אבטחה:** אסימוני גישה פגים לאחר שעה
* **אבטחה:** נדרש PKCE (S256) כדי למנוע יירוט קוד אישור
* **תוקן:** טיפול באזור זמן באימות תפוגת אסימונים
* **תוקן:** תוכנת ביניים לאימות אסימון נושא
* **תוקן:** קוד לא נגיש בבדיקות הרשאות

**נדרשת העברה:** שילובי שם משתמש/סיסמה קיימים חייבים לעבור ל-OAuth 2.0. עיין בתיעוד לקבלת פרטים.

### גרסה 0.1.2 - 19-02-2026
* **נוסף:** תמיכה בתרגום ל-12 שפות (ar, de_DE, en_US, es_ES, fr_FR, he_IL, it_IT, ja, nl_NL, pt_BR, ru_RU, zh_CN)
* **תיקון:** הכללת composer.json ו-composer.lock בבניית קבצי WordPress.org

### גרסה 0.1.1 - 16-02-2026
* כלול תלות של ספקים (firebase/php-jwt, predis/predis) בהפצה
* עדכון תיעוד ההתקנה - אין צורך בהתקנה ידנית של מלחין
* הוסף .distignore להפצה ב-WordPress.org
* הוסף בדיקת תלות עם הודעת מנהל
* שיפור זרימת העבודה של הפצת תוספים

### גרסה 0.1.0 - 13-02-2025
* יציאה ראשונית לציבור
* תמיכה בפרוטוקול WebMCP
* 5 כלי ליבה של WordPress (searchPosts, getPost, searchPages, getPage, getCurrentUser)
* הגבלת קצב (טרנזיינטציות של Redis + WordPress)
* בקרות אבטחה (רשימה שחורה של משתמשים)
* יצירת מניפסט אוטומטית
* מוכן להפקה

**הערה:** גרסה 0.1.0 השתמשה באימות שם משתמש/סיסמה. גרסה זו הוחלף ב-OAuth 2.0 בגרסה 0.2.0.

---

## 🤝 תרומה

מצאתם באג או רוצים לתרום? פתחו בעיה בפורום התמיכה של WordPress.org.

---

## 📄 רישיון

GPL-3.0 או גרסה מתקדמת יותר

---

**נוצר באמצעות ❤️ עבור קהילת WordPress ובינה מלאכותית**
