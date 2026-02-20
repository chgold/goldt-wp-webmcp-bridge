# AI Connect - גשר WebMCP עבור וורדפרס

![WordPress Plugin Version](https://img.shields.io/badge/version-0.1.2-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/license-GPL--3.0-green.svg)

חברו סוכני בינה מלאכותית (ChatGPT, Claude, או כל בינה מלאכותית מותאמת אישית) לאתר וורדפרס שלכם באמצעות **אימות פשוט ומאובטח** באמצעות פרוטוקול WebMCP.

**אין צורך בהגדרת OAuth מורכבת!** רק שם משתמש וסיסמה.

---

## 🚀 התחלה מהירה

### התקנה

1. העלה את התוסף ל- `/wp-content/plugins/ai-connect/`
2. הפעלה דרך מנהל מערכת של וורדפרס

**זהו!** אין צורך בהגדרות.

**הערה:** התוסף כולל את כל התלויות הנדרשות:**
- `firebase/php-jwt` (גרסה 6.10.0) - טיפול באסימוני JWT
- `predis/predis` (v2.4.1) - לקוח Redis להגבלת תעריפים (אופציונלי)

אין צורך בהתקנה ידנית של `composer` - הכל מגיע בחבילה!

---


## 📖 מדריך אימות

### איך זה עובד

AI Connect משתמש באימות ישיר - כל סוכן בינה מלאכותית יכול להתחבר לאתר הוורדפרס שלך באמצעות שם משתמש וסיסמה של וורדפרס. אין צורך ברישום מראש!

### שלב 1: התחברות וקבלת טוקנים

**בקשה:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "your_wordpress_username",
    "password": "your_wordpress_password"
  }'
```

**תגובה:**
```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "def50200a1b2c3...",
  "scope": "read write",
  "user_id": 1,
  "user_login": "admin",
  "user_email": "admin@example.com"
}
```

⚠️ **הערת אבטחה:** תוקפם של טוקני גישה פג לאחר שעה. יש לאחסן את טוקני הרענון כדי לקבל טוקני גישה חדשים מבלי לבצע אימות מחדש.

---

### שלב 2: שימוש ב-API

**בקשה:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.searchPosts" \
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

### שלב 3: רענון האסימון (לאחר שעה)

תוקפם של טוקנים לגישה פג לאחר שעה. השתמש באסימון הרענון שלך כדי לקבל אחד חדש:

**בקשה:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/oauth/refresh" \
  -H "Content-Type: application/json" \
  -d '{
    "refresh_token": "def50200a1b2c3..."
  }'
```

**תגובה:**
```json
{
  "access_token": "NEW_ACCESS_TOKEN",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

---

## 🛠️ כלים זמינים

### 1. wordpress.searchפוסטים

חפש פוסטים בוורדפרס באמצעות פילטרים.

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
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.searchPosts" \
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
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.getPost" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier": 123}'
```

---

### 3. wordpress.searchPages

חפש דפי וורדפרס.

**פרמטרים:**
- `search` (מחרוזת, אופציונלי) - שאילתת חיפוש
- `parent` (מספר שלם, אופציונלי) - מזהה דף האב
- `status` (מחרוזת, אופציונלי) - סטטוס דף (ברירת מחדל: `publish`)
- `limit` (מספר שלם, אופציונלי) - מקסימום תוצאות (ברירת מחדל: 10)

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.searchPages" \
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
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.getPage" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"identifier": "about-us"}'
```

---

### 5. wordpress.getCurrentUser

קבל מידע על המשתמש המאומת.

**דוגמה:**
```bash
curl -X POST "http://yoursite.com/wp-json/ai-connect/v1/tools/wordpress.getCurrentUser" \
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

נווט אל **מנהל וורדפרס → חיבור AI → הגדרות** כדי לנהל את האבטחה:

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
1. עבור אל **AI Connect ← הגדרות ← ניהול גישת משתמש**
2. הזן את שם המשתמש של וורדפרס
3. לחץ על "חסום משתמש"

**תוצאה:** המשתמש אינו יכול לאמת או להשתמש באסימונים קיימים, גם אם הם תקפים.

**כדי לשחזר גישה:** לחץ על "שחזר גישה" ליד משתמש חסום.

---

## 📊 הגבלת קצב

מגבלות ברירת מחדל (למשתמש):
- **50 בקשות לדקה**
- **1,000 בקשות לשעה**

**הגדר ב:** חיבור AI → הגדרות

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
2. **השתמש בסיסמאות אפליקציות** (וורדפרס 5.6+) - מאובטח יותר מסיסמאות רגילות
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

### שגיאות נפוצות

#### ``אימות_נכשל`` - שם משתמש או סיסמה לא חוקיים
**פתרון:** ודא שהפרטים של וורדפרס נכונים

#### ``access_denied`` - משתמש חסום
**פתרון:** בדוק אם המשתמש נמצא ברשימה השחורה (AI Connect → הגדרות)

#### ``פג תוקפו של האסימון``
**פתרון:** השתמש באסימון רענון כדי לקבל אסימון גישה חדש

#### ``חרגתי ממגבלת התעריף``
**פתרון:**
- המתן לזמן ניסיון חוזר (סמן `retry_after` בתגובה)
- הגדלת המגבלות ב- **AI Connect → הגדרות**

#### שגיאות 404 ב-API של REST
**פתרון:**
1. עבור אל **הגדרות → קישורים קבועים**
2. לחץ על **שמור שינויים** (בטל את כללי הכתיבה מחדש)
3. בדוק שוב

---

## 📝 דוגמאות קוד

### ג'אווהסקריפט

```javascript
// Login and get tokens
async function loginToWordPress(siteUrl, username, password) {
  const response = await fetch(`${siteUrl}/wp-json/ai-connect/v1/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password })
  });
  
  const tokens = await response.json();
  return tokens.access_token;
}

// Use the API
async function searchPosts(siteUrl, accessToken, query) {
  const response = await fetch(`${siteUrl}/wp-json/ai-connect/v1/tools/wordpress.searchPosts`, {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${accessToken}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ search: query, limit: 10 })
  });
  
  const data = await response.json();
  return data;
}

// Example usage
const token = await loginToWordPress('https://yoursite.com', 'admin', 'password');
const posts = await searchPosts('https://yoursite.com', token, 'hello');
console.log(posts);
```

---

### פייתון

```python
import requests

class WordPressAI:
    def __init__(self, site_url, username, password):
        self.site_url = site_url
        self.access_token = None
        self.refresh_token = None
        self.login(username, password)
    
    def login(self, username, password):
        """Authenticate and get tokens"""
        response = requests.post(
            f"{self.site_url}/wp-json/ai-connect/v1/auth/login",
            json={'username': username, 'password': password}
        )
        response.raise_for_status()
        
        data = response.json()
        self.access_token = data['access_token']
        self.refresh_token = data['refresh_token']
    
    def refresh_access_token(self):
        """Get new access token using refresh token"""
        response = requests.post(
            f"{self.site_url}/wp-json/ai-connect/v1/oauth/refresh",
            json={'refresh_token': self.refresh_token}
        )
        response.raise_for_status()
        
        data = response.json()
        self.access_token = data['access_token']
    
    def call_tool(self, tool_name, params):
        """Call a WordPress tool"""
        response = requests.post(
            f"{self.site_url}/wp-json/ai-connect/v1/tools/{tool_name}",
            headers={'Authorization': f'Bearer {self.access_token}'},
            json=params
        )
        
        # Handle token expiry
        if response.status_code == 401:
            self.refresh_access_token()
            return self.call_tool(tool_name, params)
        
        response.raise_for_status()
        return response.json()

# Example usage
wp = WordPressAI('https://yoursite.com', 'admin', 'password')

# Search posts
posts = wp.call_tool('wordpress.searchPosts', {'search': 'hello', 'limit': 5})
print(posts)

# Get current user
user = wp.call_tool('wordpress.getCurrentUser', {})
print(user)
```

---

## 🔧 פיתוח

### בדיקות מקומיות

```bash
# Start WordPress with PHP built-in server
cd /var/www/wp
php -S localhost:8888

# Test login
curl -X POST "http://localhost:8888/wp-json/ai-connect/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "admin"}'

# Test API call
curl -X POST "http://localhost:8888/wp-json/ai-connect/v1/tools/wordpress.getCurrentUser" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{}'
```

---

### הוסף כלים מותאמים אישית

```php
add_action('ai_connect_register_modules', function($ai_connect) {
    $manifest = $ai_connect->get_manifest_instance();
    
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

### גרסה 0.1.2 - 19-02-2026
* **נוסף:** תמיכה בתרגום ל-12 שפות (ar, de_DE, en_US, es_ES, fr_FR, he_IL, it_IT, ja, nl_NL, pt_BR, ru_RU, zh_CN)
* **תיקון:** הכללת composer.json ו-composer.lock בבניית קבצי WordPress.org

### גרסה 0.1.1 - 16-02-2026
* כלול בהפצה תלות של ספקים (firebase/php-jwt, predis/predis)
* עדכון תיעוד ההתקנה - אין צורך בהתקנה ידנית של מלחין
* הוסף .distignore להפצה ב-WordPress.org
* הוסף בדיקת תלות עם הודעת מנהל
* שיפור זרימת העבודה של הפצת תוספים

### גרסה 0.1.0 - 13-02-2025
* יציאה ראשונית לציבור
* תמיכה בפרוטוקול WebMCP
* אימות ישיר של שם משתמש/סיסמה עם JWT
* 5 כלי ליבה של וורדפרס (searchPosts, getPost, searchPages, getPage, getCurrentUser)
* הגבלת קצב (טרנזיינטציות של Redis + WordPress)
* בקרות אבטחה (סיבוב סוד JWT, רשימה שחורה של משתמשים)
* יצירת מניפסט אוטומטית
* מוכן להפקה

---

## 🤝 תרומה

מצאת באג או רוצה לתרום? בקר ב[מאגר GitHub] שלנו (https://github.com/chgold/ai-connect).

---

## 📄 רישיון

GPL-3.0 או גרסה מתקדמת יותר

---

## 🔗 קישורים

- [מאגר גיטהאב](https://github.com/chgold/ai-connect)
- [מעקב אחר בעיות](https://github.com/chgold/ai-connect/issues)
- תיעוד (https://github.com/chgold/ai-connect/wiki)

---

**נוצר באמצעות ❤️ עבור קהילת וורדפרס ובינה מלאכותית**
