# Ідеї для покращень

## 1. Оптимізувати `overview`
- Метод `overview` у `PdpController` виконує окремий запит `skills()` для кожного PDP усередині циклу, що створює N+1 запити. Можна одразу підвантажити навички через `with()` або отримати їх одним репозиторієм, щоб зменшити навантаження на БД.【F:app/Http/Controllers/PdpController.php†L58-L66】
- Додатково для кожної навички викликається `PdpSkillCriterionProgress::query()->...->get()->count()`. Це не лише новий запит для кожного критерію, але й зайве завантаження повної колекції. Варто замінити на `distinct('criterion_index')->count('criterion_index')` або попередньо агрегаційно підрахувати значення в одному запиті.【F:app/Http/Controllers/PdpController.php†L78-L88】

## 2. Уніфікувати розбір критеріїв
- У контролері є методи `parseCriteriaItemsForOverview` та `normalizeCriteriaForTransfer`, які дублюють логіку сервісу `PdpSkillService::parseCriteriaItems`/`parseCriteriaItemsWithDone`. Через це зростає ризик розходжень формату, якщо змінювати правила лише в одному місці. Варто перенести парсинг до окремого сервісу/хелпера та повторно використати у всіх місцях.【F:app/Http/Controllers/PdpController.php†L112-L179】【F:app/Services/PdpSkillService.php†L302-L365】

## 3. Загорнути створення/копіювання PDP у транзакції
- Методи `transfer`, `assignTemplate` та `createTemplate` створюють PDP разом з навичками. Якщо додавання однієї з навичок впаде, отримаємо частково створені дані. Варто використовувати `DB::transaction`, щоб усі вставки були атомарними.【F:app/Http/Controllers/PdpController.php†L365-L465】

