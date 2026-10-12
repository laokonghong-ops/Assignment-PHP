KHMER SHOP PRO + PHP / MySQL
=============================

អ្វីដែលបានបន្ថែម
- រក្សាទុក Order និងទំនិញនីមួយៗទៅ MySQL តាម PHP API។
- ពិនិត្យព័ត៌មានភ្ញៀវ និងទិន្នន័យ Order នៅ server មុនរក្សាទុក។
- ការពារការផ្ញើ Order ស្ទួនដោយលេខ Invoice។
- រចនា និងមុខងារ Front-end ដើមត្រូវបានរក្សាទុកជាចម្បង។

របៀបដំណើរការនៅ Windows + XAMPP
1. ដំឡើង/បើក XAMPP ហើយ Start Apache និង MySQL។
2. Copy folder KHMER_SHOP_PHP ទៅ C:\xampp\htdocs\KHMER_SHOP_PHP
3. បើក http://localhost/phpmyadmin
4. ចូល Import ហើយជ្រើស database.sql ដើម្បីបង្កើត Database និង Tables។
5. ពិនិត្យ api/config.php។ តម្លៃលំនាំដើមសម្រាប់ XAMPP គឺ user=root និង password ទទេ។
   បើ MySQL របស់អ្នកមាន password សូមកែ DB_PASS។
6. បើកហាងតាម http://localhost/KHMER_SHOP_PHP/
   ត្រូវប្រើ localhost/Apache មិនមែនបើក index.php ដោយ double-click ទេ។
7. បន្ថែមទំនិញទៅកន្ត្រក → បញ្ជាទិញ → បំពេញឈ្មោះ/ទូរស័ព្ទ/អាសយដ្ឋាន
   → ចុច “បញ្ជាក់ & ផ្ញើ Order”។
8. ពិនិត្យ Order ក្នុង phpMyAdmin → khmer_shop → orders និង order_items។

ចំណាំសំខាន់
- ប៊ូតុង Telegram នៅតែបើក Telegram ដោយប្រើ username ដែលមានក្នុងកូដ។
- KHQR ដែលបង្ហាញនៅលើ Invoice គឺ string ដែលមានក្នុងគម្រោងដើម។ សូមសាកល្បងទូទាត់
  និងផ្ទៀងផ្ទាត់ជាមួយគណនីធនាគារពិត មុនប្រើប្រាស់ជាក់ស្តែង។
- កំណែនេះរក្សាទុក Order ក្នុង Database ប៉ុណ្ណោះ។ មិនមាន Admin Login/Dashboard,
  មិនបានភ្ជាប់ការទូទាត់ដោយស្វ័យប្រវត្តិ និងមិនបានដាក់ព័ត៌មានបញ្ជាក់ការទូទាត់។
- កុំដាក់គម្រោងនេះលើ Internet ដោយផ្ទាល់ មុនពេលបន្ថែម Admin authentication,
  HTTPS, rate limiting, backup និងការការពារ spam។
- ដើម្បីឱ្យ PHP ប្រើ PDO MySQL បាន ត្រូវបើក extension pdo_mysql ក្នុង php.ini
  (XAMPP ជាទូទៅបានបើករួច)។
