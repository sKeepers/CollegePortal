<?php

namespace App\Services;

class UatScenarioService
{
    /** @return array<string, array<int, array<string, string>>> */
    public function scenarios(): array
    {
        return [
            'director' => [
                $this->scenario('director.login', 'Вход', '/login', 'Войти под ролью директора и открыть рабочий стол.', 'Dashboard открыт, CRUD-кнопки не видны.'),
                $this->scenario('director.dashboard', 'Executive Dashboard', '/dashboard', 'Проверить KPI руководителя и блок требует внимания.', 'Показатели загружаются без ошибок.'),
                $this->scenario('director.students', 'Просмотр студентов', '/students', 'Открыть список и карточку студента.', 'Просмотр доступен, изменения недоступны.'),
                $this->scenario('director.teachers', 'Просмотр преподавателей', '/teachers', 'Проверить список и карточку преподавателя.', 'Просмотр доступен без CRUD.'),
                $this->scenario('director.schedule', 'Расписание', '/schedule', 'Открыть расписание и проверить фильтры.', 'Расписание читаемо, edit-действия скрыты.'),
                $this->scenario('director.attendance', 'Посещаемость', '/attendance', 'Открыть аналитику присутствия.', 'Сводка и фильтры работают.'),
                $this->scenario('director.audit', 'Аудит', '/admin/audit', 'Открыть журнал аудита.', 'События доступны для просмотра.'),
            ],
            'deputy' => $this->studyOfficeScenarios('deputy'),
            'study' => $this->studyOfficeScenarios('study'),
            'admission' => [
                $this->scenario('admission.fis_import', 'Импорт ФИС', '/admin/import', 'Выбрать источник ФИС, загрузить файл и выполнить analyze.', 'Файл распознан, ошибки показаны воспроизводимо.'),
                $this->scenario('admission.dry_run', 'Dry-run', '/admin/import', 'Запустить dry-run импорта.', 'База не меняется, отчет понятен.'),
                $this->scenario('admission.card', 'Карточка абитуриента', '/admissions', 'Открыть заявление и карточку справа.', 'Контакты, программа, документы и история видны.'),
                $this->scenario('admission.documents', 'Документы', '/admissions', 'Проверить вкладку документов заявления.', 'Комплектность и файлы понятны.'),
                $this->scenario('admission.bulk', 'Массовые операции', '/admissions', 'Выбрать записи и открыть preview операции.', 'Preview показывает область действия и skipped/errors.'),
                $this->scenario('admission.filters', 'Фильтры', '/admissions', 'Проверить статус, программу, документы и дату.', 'Фильтры возвращают ожидаемые записи.'),
            ],
            'teacher' => [
                $this->scenario('teacher.dashboard', 'Личный Dashboard', '/dashboard', 'Проверить занятия сегодня, журналы и быстрые действия.', 'Показаны только данные преподавателя.'),
                $this->scenario('teacher.schedule', 'Свое расписание', '/schedule', 'Открыть расписание преподавателя.', 'Чужие занятия не мешают работе.'),
                $this->scenario('teacher.open_journal', 'Открытие журнала из занятия', '/schedule', 'Нажать Открыть журнал у своего занятия.', 'Открывается или создается journal lesson.'),
                $this->scenario('teacher.topic_homework', 'Тема и домашнее задание', '/journal', 'Заполнить тему, ДЗ и комментарий.', 'Данные сохраняются без перезагрузки.'),
                $this->scenario('teacher.attendance', 'Посещаемость', '/journal', 'Отметить всех/выбранных и сохранить.', 'Статусы сохраняются.'),
                $this->scenario('teacher.grades', 'Оценки', '/journal', 'Выставить оценки и комментарии.', 'Оценки сохраняются.'),
                $this->scenario('teacher.files', 'Файлы', '/journal', 'Загрузить и скачать материал занятия.', 'Файл доступен только через API.'),
                $this->scenario('teacher.sign', 'Завершение и подпись', '/journal', 'Завершить и подписать журнал.', 'После подписи поля read-only.'),
            ],
            'security' => [
                $this->scenario('security.gate', 'Проходная', '/access/gate', 'Проверить USB/HID ввод token.', 'Скан активного QR разрешен.'),
                $this->scenario('security.mobile', 'Мобильный сканер', '/access/mobile-scanner', 'Открыть камеру телефона по HTTPS.', 'Камера запускается, scan идет через API.'),
                $this->scenario('security.revoked', 'Отозванный QR', '/access/gate', 'Просканировать revoked token.', 'Отказ с понятной причиной.'),
                $this->scenario('security.unknown', 'Неизвестный QR', '/access/gate', 'Просканировать неизвестный token.', 'Отказ без создания персональных данных.'),
                $this->scenario('security.reports', 'Отчеты проходов', '/access/reports', 'Открыть фильтры и CSV.', 'События отображаются и экспортируются.'),
            ],
            'student' => [
                $this->scenario('student.mobile', 'Мобильный кабинет', '/m/student', 'Открыть кабинет на телефоне.', 'Показаны только личные данные.'),
                $this->scenario('student.schedule', 'Расписание', '/m/student', 'Проверить расписание/ближайшее занятие.', 'Информация читаема на 390-430px.'),
                $this->scenario('student.journal', 'Журнал и оценки', '/journal', 'Проверить доступ только к разрешенным данным.', 'Админ-разделы недоступны.'),
                $this->scenario('student.qr', 'QR-пропуск', '/m/student/pass', 'Открыть QR.', 'QR читаемый и без ПДн.'),
            ],
            'admin' => [
                $this->scenario('admin.users', 'Пользователи', '/admin/users', 'Проверить UAT-пользователей.', 'Логины есть, пароли не показываются.'),
                $this->scenario('admin.roles', 'Роли', '/admin/roles', 'Проверить роли и назначения.', 'Матрица соответствует RBAC.'),
                $this->scenario('admin.permissions', 'Permissions', '/admin/permissions', 'Проверить permissions.', 'Назначения доступны.'),
                $this->scenario('admin.settings', 'Настройки', '/admin/settings', 'Проверить настройки колледжа.', 'Сохранение работает.'),
                $this->scenario('admin.reference', 'Справочники', '/admin/reference', 'Проверить системные справочники.', 'Справочники активны.'),
                $this->scenario('admin.audit', 'Аудит', '/admin/audit', 'Проверить события.', 'Фильтры и карточка работают.'),
                $this->scenario('admin.import', 'Импорт', '/admin/import', 'Проверить источники импорта.', 'Templates/preview доступны.'),
            ],
        ];
    }

    /** @return array<int, array<string, string>> */
    public function roleScenarios(string $roleCode): array
    {
        return $this->scenarios()[$roleCode] ?? [];
    }

    /** @return array<int, array{email:string, role:string}> */
    public function uatAccounts(): array
    {
        return [
            ['email' => 'admin.uat@college-portal.local', 'role' => 'admin'],
            ['email' => 'director.uat@college-portal.local', 'role' => 'director'],
            ['email' => 'deputy.uat@college-portal.local', 'role' => 'deputy'],
            ['email' => 'study.uat@college-portal.local', 'role' => 'study'],
            ['email' => 'admission.uat@college-portal.local', 'role' => 'admission'],
            ['email' => 'teacher1.uat@college-portal.local', 'role' => 'teacher'],
            ['email' => 'student1.uat@college-portal.local', 'role' => 'student'],
            ['email' => 'security.uat@college-portal.local', 'role' => 'security'],
        ];
    }

    /** @return array<int, array<string, string>> */
    private function studyOfficeScenarios(string $prefix): array
    {
        return [
            $this->scenario("{$prefix}.settings", 'Настройки учебного года', '/admin/settings', 'Проверить текущий учебный год и семестр.', 'Настройки соответствуют UAT-периоду.'),
            $this->scenario("{$prefix}.reference", 'Справочники', '/admin/reference', 'Проверить формы обучения, виды занятий и контроля.', 'Нет пустых критичных справочников.'),
            $this->scenario("{$prefix}.teachers", 'Преподаватели', '/teachers', 'Проверить преподавателей.', 'Карточки и фильтры работают.'),
            $this->scenario("{$prefix}.subjects", 'Дисциплины', '/subjects', 'Проверить дисциплины.', 'Коды и связи читаемы.'),
            $this->scenario("{$prefix}.curricula", 'Учебные планы', '/curricula', 'Проверить семестры, часы и контроль.', 'Итоги сходятся.'),
            $this->scenario("{$prefix}.groups", 'Группы и планы', '/groups', 'Проверить привязку группы к учебному плану.', 'Группа использует действующий план.'),
            $this->scenario("{$prefix}.load", 'Генерация нагрузки', '/teaching-load', 'Сформировать нагрузку из плана.', 'Preview/apply без дублей.'),
            $this->scenario("{$prefix}.assign", 'Назначение преподавателей', '/teaching-load', 'Назначить преподавателя строкам нагрузки.', 'Остатки часов пересчитаны.'),
            $this->scenario("{$prefix}.schedule", 'Визуальный редактор расписания', '/schedule', 'Создать/применить шаблон и проверить конфликты.', 'Конфликты и покрытие понятны.'),
            $this->scenario("{$prefix}.journal_control", 'Контроль журналов', '/journal', 'Открыть режим Контроль журналов.', 'Незаполненные и подписанные журналы видны.'),
        ];
    }

    private function scenario(string $code, string $title, string $route, string $steps, string $expected): array
    {
        return compact('code', 'title', 'route', 'steps', 'expected');
    }
}
