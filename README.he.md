# GoldT WebMCP Bridge - WebMCP Bridge for WordPress

![WordPress Plugin Version](https://img.shields.io/badge/version-0.4.6-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)

גשר עבור 8 סוכני AI (Claude, ChatGPT, Grok, ועוד) דרך WebMCP עם OAuth 2.0 ותיקון שפות אופציונלי

**אימות מאובטח:** משתמש ב-OAuth 2.0 (אותו תקן אבטחה כמו Google, Facebook, ו-GitHub) - הסיסמאות שלך נשארות בטוחות ופרטיות!

---

## 🚀 התחלה מהירה

### התקנה

1. העלה את תיקיית התוסף ל- `/wp-content/plugins/`
2. הפעל דרך מנהל המערכת של WordPress

**זה הכל!** אין צורך בהגדרות.

**הערה:** התוסף כולל את כל התלויות הנדרשות:
- `predis/predis` (v3.4.0) - לקוח Redis להגבלת קצב (אופציונלי)

אין צורך ב-`composer install` ידני - הכל כלול!

---

## 🤖 למשתמשי סוכני AI

**כבר התקנת את התוסף? הנה כל מה שאתה צריך!**

פשוט העתק והדבק את ההוראות הבאות לסוכן ה-AI שלך (Claude, ChatGPT, וכו'):

```
Connect to my WordPress site and follow the instructions at:
https://github.com/chgold/goldt-wp-webmcp-bridge
```

סוכן ה-AI יטפל בשאר - אישור OAuth, חיבור API, וגילוי כלים.

**זה הכל!** אין צורך בידע טכני. ✨

---

## הגדרת Claude Desktop / Cursor / OpenCode — MCP

**שלב 1** — התקן את [webmcp-client](https://www.npmjs.com/package/webmcp-client):
```bash
npm install -g webmcp-client
```

**שלב 2** — הוסף ל-`claude_desktop_config.json` (הגדר פעם אחת, לעולם אל תשנה):
```json
{
  "mcpServers": {
    "webmcp": {
      "command": "webmcp-client",
      "env": {
        "NODE_TLS_REJECT_UNAUTHORIZED": "0"
      }
    }
  }
}
```

> **הערה למשתמשי NetFree / פרוקסי SSL ארגוני:** ההגדרה `NODE_TLS_REJECT_UNAUTHORIZED: "0"` עוקפת את אימות תעודת ה-SSL. נדרש עבור רשתות שמיירטות תעבורת HTTPS.

**שלב 3** — השתמש בדף הניהול של WordPress כדי ליצור טוקן ולחבר את האתר שלך.

---

## 🔐 מדריך אימות OAuth 2.0

### איך זה עובד

GoldT WebMCP Bridge משתמש ב-**OAuth 2.0 Authorization Code Flow with PKCE** - התקן התעשייתי לאימות API מאובטח:

1. סוכן ה-AI מבקש אישור עם אתגר קוד (PKCE)
2. המשתמש מאשר בדפדפן → מקבל קוד אישור חד-פעמי
3. הסוכן מחליף את הקוד בטוקן גישה (עם מאמת קוד)
4. הסוכן משתמש בטוקן לקריאות API

**לקוחות רשומים מראש** (מוכנים לשימוש!):
- `claude-ai` - Claude AI (Anthropic)
- `chatgpt` - ChatGPT (OpenAI)
- `gemini` - Gemini (Google)
- `grok` - Grok (xAI)
- `perplexity` - Perplexity AI
- `copilot` - Microsoft Copilot
- `meta-ai` - Meta AI (Facebook)
- `deepseek` - DeepSeek AI

---

### שלב 1: יצירת פרמטרים של PKCE

```bash
# Code verifier (מחרוזת אקראית של 128 תווים)
CODE_VERIFIER=$(openssl rand -hex 64)

# Code challenge (גיבוב SHA256 של המאמת)
CODE_CHALLENGE=$(echo -n "$CODE_VERIFIER" | openssl dgst -sha256 -binary | base64 | tr '+/' '-_' | tr -d '=')

# State (להגנת CSRF)
STATE=$(openssl rand -hex 16)
```

---

### שלב 2: כתובת URL לאישור

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

**היקפים זמינים:**
- `read` - קריאת פוסטים, עמודים ותוכן
- `write` - יצירה ועדכון של פוסטים ועמודים
- `delete` - מחיקת פוסטים ועמודים
- `manage_users` - צפייה וניהול חשבונות משתמשים

המשתמש יראה מסך הסכמה ויאשר. הוא יקבל **קוד אישור** (בתוקף ל-10 דקות).

---

### שלב 3: החלפת קוד בטוקן

**בקשה:**
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

**תגובה:**
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
- קודי אישור הם **לשימוש חד-פעמי** ופוקעים תוך 10 דקות
- טוקני גישה פוקעים לאחר **שעה**
- טוקני רענון פוקעים לאחר **30 יום**
- אימות PKCE מבטיח שהטוקן ניתן לתבוע רק על ידי הלקוח שיזם את הזרימה
- **שמור את טוקן הרענון שלך** - תזדקק לו כדי לקבל טוקני גישה חדשים מבלי לאמת מחדש!

---

### שלב 4: שימוש ב-API

**בקשה:**
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

### שלב 4: רענון טוקן גישה (לאחר שעה)

טוקני גישה פוקעים לאחר שעה. השתמש בטוקן הרענון שלך כדי לקבל טוקן גישה חדש **מבלי** לאמת מחדש:

**בקשה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/token" \
  -H "Content-Type: application/json" \
  -d '{
    "grant_type": "refresh_token",
    "client_id": "claude-ai",
    "refresh_token": "wpr_8a7b6c5d4e3f2a1b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e4f3a2b1c0d9e8f7a6b"
  }'
```

**תגובה:**
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

**חשוב:**
- טוקן הגישה הישן וטוקן הרענון **מבוטלים אוטומטית**
- אתה מקבל טוקן גישה **חדש** וגם טוקן רענון **חדש**
- טוקני רענון תקפים למשך 30 יום
- אם טוקן הרענון פוקע, המשתמש חייב לאשר מחדש החל משלב 1

---

### שלב 5: ביטול טוקן (אופציונלי)

בטל טוקן גישה כשסיימת או אם הוא נפגע:

**בקשה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/oauth/revoke" \
  -H "Content-Type: application/json" \
  -d '{
    "token": "wpc_c6c9f8398c5f7921713011d19676ee2f81470cf7ec7c71ce91925cd129853dd3"
  }'
```

**תגובה:**
```json
{
  "success": true,
  "message": "Token revoked successfully"
}
```

**הערה:** ביטול טוקן גישה מבטל גם את טוקן הרענון המשויך אליו.

---

## ⚙️ הגדרות מנהל מערכת

נווט אל **מנהל המערכת של WordPress → GoldT WebMCP → הגדרות** כדי להגדיר את התוסף.

### ספק תרגום

שולט כיצד התוסף מטפל בבקשות תרגום תוכן.

| אפשרות | תיאור |
|--------|-------------|
| `ai_self` | סוכן ה-AI מתרגם בעצמו (ברירת מחדל) |
| `mymemory` | התוסף קורא ל-MyMemory API ומחזיר טקסט מתורגם |
| `disabled` | כלי התרגום אינם חשופים במניפסט |

כאשר מוגדר ל-`mymemory`, הכלים `translation.translate` ו-`translation.getSupportedLanguages` מופיעים במניפסט. כאשר מוגדר ל-`ai_self` או `disabled`, כלים אלו מוסתרים.

### הגבלת קצב

מגבלות ברירת מחדל (למשתמש):
- **50 בקשות לדקה**
- **1,000 בקשות לשעה**

התאם את שני הערכים ב-**GoldT WebMCP → הגדרות**.

---

## 🛠️ כלים זמינים

### 1. wordpress.searchPosts

חפש פוסטים ב-WordPress עם פילטרים.

**פרמטרים:**
- `search` (מחרוזת, אופציונלי) - שאילתת חיפוש
- `category` (מחרוזת, אופציונלי) - מזהה קטגוריה
- `tag` (מחרוזת, אופציונלי) - מזהה תגית
- `author` (מספר שלם, אופציונלי) - מזהה מחבר
- `status` (מחרוזת, אופציונלי) - סטטוס פוסט (ברירת מחדל: `publish`)
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

קבל פוסט בודד לפי מזהה או מזהה ייחודי.

**פרמטרים:**
- `identifier` (מספר שלם|מחרוזת, נדרש) - מזהה פוסט או מזהה ייחודי

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/wordpress.getPost" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier": 123}'
```

---

### 3. wordpress.searchPages

חפש עמודים ב-WordPress.

**פרמטרים:**
- `search` (מחרוזת, אופציונלי) - שאילתת חיפוש
- `parent` (מספר שלם, אופציונלי) - מזהה עמוד אב
- `status` (מחרוזת, אופציונלי) - סטטוס עמוד (ברירת מחדל: `publish`)
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

קבל עמוד בודד לפי מזהה או מזהה ייחודי.

**פרמטרים:**
- `identifier` (מספר שלם|מחרוזת, נדרש) - מזהה עמוד או מזהה ייחודי

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

## 🌐 מודול תרגום

זמין כאשר **ספק התרגום** מוגדר ל-`mymemory` בהגדרות מנהל המערכת. כלים אלו מוסתרים מהמניפסט כאשר הספק הוא `ai_self` או `disabled`.

### 6. translation.translate

תרגם טקסט באמצעות MyMemory API.

**פרמטרים:**
- `text` (מחרוזת, נדרש) - טקסט לתרגום
- `source_lang` (מחרוזת, נדרש) - קוד שפת מקור (למשל, `en`, `he`, `fr`)
- `target_lang` (מחרוזת, נדרש) - קוד שפת יעד

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/translation.translate" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "text": "Hello, world!",
    "source_lang": "en",
    "target_lang": "he"
  }'
```

**תגובה:**
```json
{
  "success": true,
  "data": {
    "translated_text": "שלום, עולם!",
    "source_lang": "en",
    "target_lang": "he"
  }
}
```

---

### 7. translation.getSupportedLanguages

מחזיר את רשימת קודי השפות הנתמכים על ידי MyMemory API.

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/goldt-webmcp-bridge/v1/tools/translation.getSupportedLanguages" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

**תגובה:**
```json
{
  "success": true,
  "data": {
    "languages": ["en", "he", "fr", "de", "es", "it", "pt", "ru", "ar", "zh", "ja", "ko"]
  }
}
```

---

## 🔐 בקרות מנהל מערכת

### תכונות אבטחה

נווט אל **מנהל המערכת של WordPress → GoldT WebMCP → הגדרות** כדי לנהל אבטחה:

#### 1. החלף סוד JWT

**ניתוק חירום של כל סוכני ה-AI:**
- לחץ על כפתור "Rotate JWT Secret"
- כל טוקני הגישה הקיימים הופכים לא תקפים באופן מיידי
- כל המשתמשים חייבים לאמת מחדש

**מתי להשתמש:**
- חשד לפריצת אבטחה
- אישורים אבודים/גנובים
- סיום שילוב

---

#### 2. חסימת גישת משתמש

**בטל גישה למשתמשים ספציפיים:**
1. עבור אל **מנהל המערכת של WordPress → GoldT WebMCP → הגדרות**
2. גלול למקטע "Manage User Access"
3. הזן מזהה משתמש של WordPress
4. לחץ על "Block User"

**תוצאה:** המשתמש אינו יכול לאמת או להשתמש בטוקנים קיימים, גם אם הם תקפים.

**כדי לשחזר גישה:** מצא את המשתמש החסום ברשימה ולחץ על "Restore Access".

---

## 🔐 שיטות עבודה מומלצות לאבטחה

### למנהלי אתרים

1. **צור חשבונות משתמש AI ייעודיים** - אל תשתמש בחשבון המנהל שלך
2. **השתמש בסיסמאות יישום** (WordPress 5.6+) - מאובטח יותר מסיסמאות רגילות
3. **נטר את רשימת המשתמשים החסומים** - בטל גישה כאשר היא כבר לא נחוצה
4. **הפעל אימות דו-שלבי (2FA)** - שכבת אבטחה נוספת (תוספים תואמים: Wordfence, iThemes Security)
5. **השתמש ב-HTTPS** - הצפן את כל התעבורה בסביבת ייצור

### למפתחים

1. **אחסן אישורים בצורה מאובטחת** - השתמש במשתני סביבה, לעולם אל תקודד קשה
2. **טפל בפקיעת טוקנים בצורה חלקה** - הטמע רענון אוטומטי
3. **כבד מגבלות קצב** - שמור תגובות במטמון כאשר אפשר
4. **השתמש בנקודות קצה של HTTPS** - לעולם אל תשלח אישורים דרך HTTP
5. **רענן טוקני רענון** - קבל חדשים באופן תקופתי

---

## 🐛 פתרון בעיות

### שגיאות OAuth נפוצות

#### `"invalid_client"` - client_id לא תקין
**פתרון:** הפרמטר `client_id` הוא אופציונלי - השמטתו מגדירה ברירת מחדל ל-`claude`, אשר מתורגם אוטומטית ל-`claude-ai`. התאמה עמומה מזהה וריאנטים נפוצים עם קווים תחתונים או מקפים (למשל, `claude`, `claude_ai`, `claude-ai`, `gemini_client`, `gemini-client` כולם עובדים).

#### `"invalid_grant"` - קוד אישור לא תקין
**פתרון:**
- קודי אישור הם לשימוש חד-פעמי ופוקעים לאחר 10 דקות
- בקש קוד אישור חדש

#### `"PKCE verification failed"`
**פתרון:** ודא שאתה משתמש באותו `code_verifier` שיצר את ה-`code_challenge`

#### `"access_denied"` - משתמש חסום
**פתרון:** בדוק אם המשתמש נמצא ברשימה השחורה (GoldT WebMCP → OAuth Tokens)

#### `"Token expired"`
**פתרון:** טוקני גישה פוקעים לאחר שעה. בקש אישור חדש.

#### `"Rate limit exceeded"`
**פתרון:**
- המתן לתקופת ניסיון חוזר (בדוק את `retry_after` בתגובה)
- הגדל מגבלות ב-**GoldT WebMCP → הגדרות**

#### שגיאות REST API 404

**פתרון:**
1. עבור אל **הגדרות → Permalinks**
2. לחץ על **שמור שינויים** (מנקה כללי כתיבה מחדש)
3. נסה שוב

---

## 🔧 למפתחים

### הרצת בדיקות

```bash
cd wp-content/plugins/goldt-webmcp-bridge
./tests/test-endpoints.sh
```

זה בודק:
- כל נקודות הקצה של הכלים
- אימות טוקן Bearer של OAuth 2.0
- נקודות קצה ציבוריות לעומת מוגנות
- טיפול בשגיאות

---

### הוספת כלים מותאמים אישית

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

**חשוב:** כלים מותאמים אישית נשמרים במהלך עדכוני תוספים. מקם את הקוד שלך בקובץ `functions.php` של התבנית שלך או בתוסף מותאם אישית כדי להבטיח שהוא נשמר לאורך עדכונים.

---

## 📋 יומן שינויים

### גרסה 0.4.6 - 2026-05-20
* **תוקן (אבטחה):** `searchPosts` ו-`searchPages` הוחזרו מ-`post_status=>'any'` בחזרה ל-`'publish'`. `WP_Query` עם `'any'` עוקף לחלוטין את בדיקות ההרשאות של WordPress — הוא מחזיר את כל הפוסטים כולל טיוטות של משתמשים אחרים גם למנויים. קידוד קשיח של `'publish'` מבטיח שרק תוכן שפורסם יוחזר. פרמטר הסכמה `status` נשאר מוסר (מאז 0.4.4).

### גרסה 0.4.5 - 2026-05-19
* **תוקן:** כפתור העתק קוד בדף קוד האישור (OOB) נכשל בשקט — פונקציית JavaScript `copyCode()` חסרה לחלוטין. נוספה עם `navigator.clipboard` API + גיבוי `execCommand`.
* **נוסף:** `webmcp-master.ai` נוסף לרשימת הפלטפורמות הנתמכות בדף המידע `/ai-connect/`.

### גרסה 0.4.4 - 2026-05-19
* **תוקן:** ביקורת WordPress.org — כתובות URL של שירות חיצוני MyMemory עודכנו לכתובות עובדות.
* **תוקן:** ביקורת WordPress.org — `searchPosts` ו-`searchPages` משתמשים כעת בסינון הרשאות מקורי של WordPress (`post_status => 'any'`): מנויים רואים רק פוסטים שפורסמו, מחברים רואים טיוטות משלהם, עורכים/מנהלים רואים הכל. פרמטר `status` הוסר מסכמת הכלים מכיוון שהוא כבר לא נחוץ.
* **תוקן:** ביקורת WordPress.org — `getPost` ו-`getPage` אוכפים כעת `current_user_can('read_post')` עבור תוכן שאינו שפורסם, ומונעים גישה לא מורשית לטיוטות/עמודים פרטיים לפי מזהה.

### גרסה 0.4.3 - 2026-05-19
* **תוקן:** אזהרות `wp plugin check` — שמות משתני תצוגה ללא קידומת שונו לקידומת `goldtwmcp_`; עטף את `$table` ב-`esc_sql()` בשאילתות פירוק סכמה; דיכא אזהרות `PluginCheck.Security.DirectDB` שגויות על קטעי SQL ברשימה לבנה.

### גרסה 0.4.2 - 2026-05-18
* **תוקן:** תאימות לסטנדרטים של קוד WordPress PHPCS — נפתרו 16 שגיאות ו-3 אזהרות ב-`class-database.php`, `class-token-registry.php`, `class-oauth-server.php`, ו-`admin-token-registry.php`.

### גרסה 0.4.1 - 2026-05-13
* **נוסף:** המניפסט חושף כעת `auth.registered_clients` — אובייקט הממפה כל `client_id` של OAuth רשום לשם התצוגה שלו (`{"webmcp-master": "WebMCP Master", "claude-ai": "Claude AI (Anthropic)", ...}`). מאפשר לסוכני AI לגלות לקוחות מקובלים ישירות מהמניפסט.
* **נוסף:** לקוח OAuth חדש כברירת מחדל `webmcp-master` (שם תצוגה "WebMCP Master") עם היקפים מלאים (`read`, `write`, `delete`, `manage_users`). נזרע בהתקנות חדשות ומוכנס באופן אידמפוטנטי בשדרוג עבור אתרים קיימים.
* **סכמה:** שדרוג מסד נתונים `1.4.0` — `upgrade_to_1_4_0()` בודק אם `webmcp-master` קיים ומוסיף אותו אם חסר (ללא כפילויות).

### גרסה 0.4.0 - 2026-05-11
* **נוסף:** טבלת צדדית של רשם טוקנים (`{prefix}aiconnect_token_registry`) — רושמת כל טוקן שהונפק/עודכן תוך שימוש רק בקידומת 16 התווים (סודות מלאים לעולם אינם נשמרים ברשם). עוקב אחר issued_at, expires_at, last_used_at, revoked_at, revoked_by, source (generator|oauth|refresh) ו-ip_address.
* **נוסף:** נקודות קצה של REST מנהל מערכת — `GET /wp-json/goldt-mcp/v1/admin/tokens` (רשימה עם פילטרים `status` / `user_id` / `limit` / `offset`) ו-`DELETE /wp-json/goldt-mcp/v1/admin/tokens/{id}` (מחיקה רכה). שתיהן דורשות `manage_options`.
* **נוסף:** דף משנה ב-WP-Admin "AI Connect → Token Registry" עם פילטרים פעילים/מבוטלים/הכל וכפתורי ביטול בכל שורה (מוגנים ב-nonce).
* **שופר:** כל חיפוש אימות bearer מוצלח מעדכן כעת את `last_used_at` כך שמנהלי מערכת יוכלו לראות מתי כל טוקן היה פעיל לאחרונה.
* **שופר:** ביטול טוקן הפך למחיקה רכה אמיתית — `revoked_at` + `revoked_by` מוגדרים במקום מחיקת שורות קשיחות. סיבוב טוקני רענון, נקודת הקצה העצמאית `/oauth/revoke`, וביטולי מנהל מערכת כולם זורמים דרך אותו עדכון רשם.
* **שופר:** `validate_token()` דוחה כעת גם טוקנים שבוטלו ברשם (הגנה לעומק — מכסה מקרים בהם הרשם סוטה מטבלת `oauth_tokens` הישנה).
* **סכמה:** שדרוג מסד נתונים `1.3.0` — `dbDelta` יוצר את הטבלה החדשה בעת הפעלת התוסף. נתיב שדרוג אידמפוטנטי מטפל בהתקנות קיימות חלקיות על ידי ALTER-הוספת עמודות חסרות.

### גרסה 0.3.3 - 2026-05-06
* **תוקן:** קישור הקרדיט "Powered by AI Connect" הוסר מדף המידע המוצג לציבור (תאימות WordPress.org)

### גרסה 0.3.2 - 2026-04-12
* **תוקן:** כפתור "Revoke All Tokens" מבטל כעת את כל הטוקנים הפעילים במסד הנתונים (בעבר שמר סוד JWT שלא היה בשימוש מעולם)
* **תוקן:** שמות כלים משתמשים כעת בקידומת מודול באותיות קטנות (`wordpress.searchPosts`) לפי מפרט פרוטוק