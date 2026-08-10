<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['code' => 'admin', 'name' => 'Администратор', 'description' => 'Полный доступ к системе.'],
            ['code' => 'director', 'name' => 'Директор', 'description' => 'Управленческий просмотр отчетов и сводок.'],
            ['code' => 'deputy', 'name' => 'Заместитель директора', 'description' => 'Контроль учебного процесса, отчетов и справочников.'],
            ['code' => 'study', 'name' => 'Учебная часть 1', 'description' => 'Расписание, замены, учебная нагрузка и учебные планы.'],
            ['code' => 'study_records', 'name' => 'Учебная часть 2', 'description' => 'Контингент, журнал успеваемости, посещаемость и выпуск.'],
            ['code' => 'admission', 'name' => 'Приемная комиссия', 'description' => 'Работа с абитуриентами и приемными кампаниями.'],
            ['code' => 'teacher', 'name' => 'Преподаватель', 'description' => 'Работа со своим расписанием, журналом и нагрузкой.'],
            ['code' => 'student', 'name' => 'Студент', 'description' => 'Просмотр личного кабинета, QR, расписания и оценок.'],
            ['code' => 'employee', 'name' => 'Сотрудник', 'description' => 'Просмотр рабочего стола и личных данных.'],
            ['code' => 'security', 'name' => 'Сотрудник проходной', 'description' => 'Сканирование QR и отчеты проходной.'],
            ['code' => 'hr', 'name' => 'Отдел кадров', 'description' => 'Ведение сотрудников, подразделений, должностей и кадровых статусов.'],
            ['code' => 'academic_office', 'name' => 'Учебная часть (legacy)', 'description' => 'Legacy-роль для совместимости.'],
            ['code' => 'curator', 'name' => 'Куратор группы', 'description' => 'Сопровождение закрепленной учебной группы.'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['code' => $role['code']], $role);
        }

        foreach ($this->permissions() as $permission) {
            Permission::updateOrCreate(
                ['code' => $permission['code']],
                $permission + ['system' => true, 'active' => true]
            );
        }

        $all = Permission::query()->pluck('id');
        $this->syncPermissions('admin', $all);
        $this->syncPermissions('director', $this->ids($this->directorPermissions()));
        $this->syncPermissions('deputy', $this->ids($this->academicEditorPermissions()));
        $this->syncPermissions('study', $this->ids($this->studySchedulePermissions()));
        $this->syncPermissions('study_records', $this->ids($this->studyRecordsPermissions()));
        $this->syncPermissions('academic_office', $this->ids($this->academicEditorPermissions()));
        $this->syncPermissions('admission', $this->ids($this->admissionPermissions()));
        $this->syncPermissions('teacher', $this->ids($this->teacherPermissions()));
        $this->syncPermissions('student', $this->ids($this->studentPermissions()));
        $this->syncPermissions('employee', $this->ids(['dashboard.view', 'view_own_data']));
        $this->syncPermissions('security', $this->ids($this->securityPermissions()));
        $this->syncPermissions('hr', $this->ids($this->hrPermissions()));
        $this->syncPermissions('curator', $this->ids(array_values(array_unique(array_merge($this->teacherPermissions(), ['students.view', 'groups.view', 'attendance.reports'])))));
    }

    private function permissions(): array
    {
        return [
            ['module' => 'Dashboard', 'code' => 'dashboard.view', 'name' => 'Просмотр Dashboard', 'description' => 'Открытие рабочего стола.'],
            ['module' => 'Identity', 'code' => 'people.view', 'name' => 'Person: просмотр', 'description' => 'Просмотр единого реестра физических лиц.'],
            ['module' => 'Identity', 'code' => 'people.create', 'name' => 'Person: создание', 'description' => 'Создание Person через защищенный API.'],
            ['module' => 'Identity', 'code' => 'people.update', 'name' => 'Person: изменение', 'description' => 'Редактирование общих данных Person через защищенный API.'],
            ['module' => 'Identity', 'code' => 'people.link', 'name' => 'Person: связывание', 'description' => 'Связывание профилей с Person.'],
            ['module' => 'Identity', 'code' => 'people.merge', 'name' => 'Person: объединение', 'description' => 'Будущая операция объединения Person.'],
            ['module' => 'Students', 'code' => 'students.view', 'name' => 'Студенты: просмотр', 'description' => 'Просмотр списка и карточек студентов.'],
            ['module' => 'Students', 'code' => 'students.create', 'name' => 'Студенты: создание', 'description' => 'Создание студентов.'],
            ['module' => 'Students', 'code' => 'students.update', 'name' => 'Студенты: изменение', 'description' => 'Редактирование студентов, импорт и фото.'],
            ['module' => 'Students', 'code' => 'students.delete', 'name' => 'Студенты: удаление', 'description' => 'Удаление студентов.'],
            ['module' => 'Students', 'code' => 'students.bulk_group', 'name' => 'Студенты: массовое назначение группы', 'description' => 'Массовое назначение группы студентам.'],
            ['module' => 'Students', 'code' => 'students.bulk_status', 'name' => 'Студенты: массовое изменение статуса', 'description' => 'Массовое изменение статуса студентов.'],
            ['module' => 'Students', 'code' => 'students.bulk_course', 'name' => 'Студенты: массовое изменение курса', 'description' => 'Массовое изменение курса студентов.'],
            ['module' => 'Students', 'code' => 'students.bulk_education', 'name' => 'Студенты: массовое изменение формы обучения', 'description' => 'Массовое изменение формы обучения и финансирования студентов.'],
            ['module' => 'Students', 'code' => 'students.bulk_passes', 'name' => 'Студенты: массовый выпуск QR', 'description' => 'Массовый выпуск цифровых пропусков студентам.'],
            ['module' => 'Students', 'code' => 'students.bulk_accounts', 'name' => 'Студенты: массовая выдача учетных записей', 'description' => 'Создание учетных записей группе разом с одноразовым показом паролей.'],
            ['module' => 'Students', 'code' => 'students.bulk_archive', 'name' => 'Студенты: массовое архивирование', 'description' => 'Массовое архивирование студентов без удаления.'],
            ['module' => 'Students', 'code' => 'students.bulk_export', 'name' => 'Студенты: массовый экспорт', 'description' => 'Экспорт выбранных студентов.'],
            ['module' => 'Groups', 'code' => 'groups.view', 'name' => 'Группы: просмотр', 'description' => 'Просмотр групп.'],
            ['module' => 'Groups', 'code' => 'groups.create', 'name' => 'Группы: создание', 'description' => 'Создание групп.'],
            ['module' => 'Groups', 'code' => 'groups.update', 'name' => 'Группы: изменение', 'description' => 'Редактирование групп и импорт.'],
            ['module' => 'Groups', 'code' => 'groups.delete', 'name' => 'Группы: удаление', 'description' => 'Удаление групп.'],
            ['module' => 'Teachers', 'code' => 'teachers.view', 'name' => 'Преподаватели: просмотр', 'description' => 'Просмотр преподавателей.'],
            ['module' => 'Teachers', 'code' => 'teachers.create', 'name' => 'Преподаватели: создание', 'description' => 'Создание преподавателей.'],
            ['module' => 'Teachers', 'code' => 'teachers.update', 'name' => 'Преподаватели: изменение', 'description' => 'Редактирование преподавателей, импорт и фото.'],
            ['module' => 'Teachers', 'code' => 'teachers.delete', 'name' => 'Преподаватели: удаление', 'description' => 'Удаление преподавателей.'],
            ['module' => 'Subjects', 'code' => 'subjects.view', 'name' => 'Дисциплины: просмотр', 'description' => 'Просмотр дисциплин.'],
            ['module' => 'Subjects', 'code' => 'subjects.create', 'name' => 'Дисциплины: создание', 'description' => 'Создание дисциплин.'],
            ['module' => 'Subjects', 'code' => 'subjects.update', 'name' => 'Дисциплины: изменение', 'description' => 'Редактирование дисциплин и импорт.'],
            ['module' => 'Subjects', 'code' => 'subjects.delete', 'name' => 'Дисциплины: удаление', 'description' => 'Удаление дисциплин.'],
            ['module' => 'Classrooms', 'code' => 'classrooms.view', 'name' => 'Аудитории: просмотр', 'description' => 'Просмотр аудиторий.'],
            ['module' => 'Classrooms', 'code' => 'classrooms.create', 'name' => 'Аудитории: создание', 'description' => 'Создание аудиторий.'],
            ['module' => 'Classrooms', 'code' => 'classrooms.update', 'name' => 'Аудитории: изменение', 'description' => 'Редактирование аудиторий и импорт.'],
            ['module' => 'Classrooms', 'code' => 'classrooms.delete', 'name' => 'Аудитории: удаление', 'description' => 'Удаление аудиторий.'],
            ['module' => 'Schedule', 'code' => 'schedule.view', 'name' => 'Расписание: просмотр', 'description' => 'Просмотр расписания.'],
            ['module' => 'Schedule', 'code' => 'schedule.create', 'name' => 'Расписание: создание', 'description' => 'Создание занятий через Schedule Engine.'],
            ['module' => 'Schedule', 'code' => 'schedule.update', 'name' => 'Расписание: изменение', 'description' => 'Создание, изменение и удаление занятий.'],
            ['module' => 'Schedule', 'code' => 'schedule.delete', 'name' => 'Расписание: удаление', 'description' => 'Удаление занятий расписания.'],
            ['module' => 'Schedule', 'code' => 'schedule.validate', 'name' => 'Расписание: проверка', 'description' => 'Preview и validation перед применением расписания.'],
            ['module' => 'Schedule', 'code' => 'schedule.manage_templates', 'name' => 'Расписание: шаблоны', 'description' => 'Управление недельными шаблонами расписания.'],
            ['module' => 'Schedule', 'code' => 'schedule.manage_replacements', 'name' => 'Расписание: замены', 'description' => 'Замены, переносы, отмены и восстановления занятий.'],
            ['module' => 'Schedule', 'code' => 'schedule.view_conflicts', 'name' => 'Расписание: конфликты', 'description' => 'Просмотр конфликтов расписания.'],
            ['module' => 'Schedule', 'code' => 'schedule.view_coverage', 'name' => 'Расписание: покрытие нагрузки', 'description' => 'Просмотр покрытия часов нагрузки расписанием.'],
            ['module' => 'Journal', 'code' => 'journal.view', 'name' => 'Журнал: просмотр', 'description' => 'Просмотр журнала.'],
            ['module' => 'Journal', 'code' => 'journal.edit', 'name' => 'Журнал: ведение', 'description' => 'Внесение темы, домашнего задания, посещаемости и оценок.'],
            ['module' => 'Journal', 'code' => 'journal.attendance', 'name' => 'Журнал: посещаемость', 'description' => 'Ведение посещаемости занятия.'],
            ['module' => 'Journal', 'code' => 'journal.grades', 'name' => 'Журнал: оценки', 'description' => 'Выставление оценок занятия.'],
            ['module' => 'Journal', 'code' => 'journal.files', 'name' => 'Журнал: материалы', 'description' => 'Загрузка и удаление материалов занятия.'],
            ['module' => 'Journal', 'code' => 'journal.complete', 'name' => 'Журнал: завершение', 'description' => 'Завершение занятия журнала.'],
            ['module' => 'Journal', 'code' => 'journal.sign', 'name' => 'Журнал: подпись', 'description' => 'Подписание занятия журнала.'],
            ['module' => 'Journal', 'code' => 'journal.reopen', 'name' => 'Журнал: исправление подписанного', 'description' => 'Исправление подписанных занятий.'],
            ['module' => 'Journal', 'code' => 'journal.view_all', 'name' => 'Журнал: просмотр всех занятий', 'description' => 'Просмотр журналов всех преподавателей и групп.'],
            ['module' => 'Journal', 'code' => 'journal.export', 'name' => 'Журнал: экспорт', 'description' => 'Экспорт отчетов журнала.'],
            ['module' => 'Attendance', 'code' => 'attendance.view', 'name' => 'Посещаемость: просмотр', 'description' => 'Просмотр аналитики посещаемости.'],
            ['module' => 'Attendance', 'code' => 'attendance.reports', 'name' => 'Посещаемость: отчеты', 'description' => 'Исторические отчеты посещаемости.'],
            ['module' => 'Admissions', 'code' => 'admissions.view', 'name' => 'Приемная комиссия: просмотр', 'description' => 'Просмотр заявлений.'],
            ['module' => 'Admissions', 'code' => 'admissions.edit', 'name' => 'Приемная комиссия: ведение', 'description' => 'Создание, изменение, импорт и зачисление заявлений.'],
            ['module' => 'Admissions', 'code' => 'admissions.applicant.view', 'name' => 'Приемная комиссия: абитуриенты просмотр', 'description' => 'Просмотр foundation-профилей абитуриентов.'],
            ['module' => 'Admissions', 'code' => 'admissions.applicant.manage', 'name' => 'Приемная комиссия: абитуриенты управление', 'description' => 'Совместимое общее разрешение управления foundation-профилями абитуриентов.'],
            ['module' => 'Admissions', 'code' => 'admissions.applicant.create', 'name' => 'Приемная комиссия: абитуриенты создание', 'description' => 'Создание foundation-профилей абитуриентов.'],
            ['module' => 'Admissions', 'code' => 'admissions.applicant.update', 'name' => 'Приемная комиссия: абитуриенты изменение', 'description' => 'Изменение foundation-профилей абитуриентов.'],
            ['module' => 'Admissions', 'code' => 'admissions.applicant.archive', 'name' => 'Приемная комиссия: абитуриенты архивирование', 'description' => 'Архивирование foundation-профилей абитуриентов без физического удаления.'],
            ['module' => 'Admissions', 'code' => 'admissions.application.view', 'name' => 'Приемная комиссия: заявления просмотр', 'description' => 'Просмотр foundation-заявлений абитуриентов.'],
            ['module' => 'Admissions', 'code' => 'admissions.application.create', 'name' => 'Приемная комиссия: заявления создание', 'description' => 'Создание черновика заявления.'],
            ['module' => 'Admissions', 'code' => 'admissions.application.update', 'name' => 'Приемная комиссия: заявления изменение', 'description' => 'Изменение допустимых полей черновика заявления.'],
            ['module' => 'Admissions', 'code' => 'admissions.application.register', 'name' => 'Приемная комиссия: заявления регистрация', 'description' => 'Регистрация черновика заявления.'],
            ['module' => 'Admissions', 'code' => 'admissions.application.manage', 'name' => 'Приемная комиссия: заявления управление', 'description' => 'Будущее расширенное управление заявлениями.'],
            ['module' => 'Admissions', 'code' => 'admissions.choice.view', 'name' => 'Приемная комиссия: выборы программ просмотр', 'description' => 'Просмотр выбранных образовательных программ заявления.'],
            ['module' => 'Admissions', 'code' => 'admissions.choice.create', 'name' => 'Приемная комиссия: выборы программ создание', 'description' => 'Добавление выбранной образовательной программы к заявлению.'],
            ['module' => 'Admissions', 'code' => 'admissions.choice.update', 'name' => 'Приемная комиссия: выборы программ изменение', 'description' => 'Изменение выбранной образовательной программы заявления.'],
            ['module' => 'Admissions', 'code' => 'admissions.choice.delete', 'name' => 'Приемная комиссия: выборы программ удаление', 'description' => 'Архивирование выбранной образовательной программы заявления.'],
            ['module' => 'Admissions', 'code' => 'admissions.document.view', 'name' => 'Приемная комиссия: документы просмотр', 'description' => 'Просмотр структурированных foundation-документов абитуриента.'],
            ['module' => 'Admissions', 'code' => 'admissions.document.create', 'name' => 'Приемная комиссия: документы создание', 'description' => 'Создание структурированных foundation-документов.'],
            ['module' => 'Admissions', 'code' => 'admissions.document.update', 'name' => 'Приемная комиссия: документы изменение', 'description' => 'Изменение реквизитов структурированных foundation-документов.'],
            ['module' => 'Admissions', 'code' => 'admissions.document.delete', 'name' => 'Приемная комиссия: документы архивирование', 'description' => 'Архивирование foundation-документов и файлов без физического удаления.'],
            ['module' => 'Admissions', 'code' => 'admissions.document.verify', 'name' => 'Приемная комиссия: документы проверка', 'description' => 'Изменение статуса проверки foundation-документов.'],
            ['module' => 'Admissions', 'code' => 'admissions.document.download_sensitive', 'name' => 'Приемная комиссия: скачать чувствительные документы', 'description' => 'Скачивание файлов документов из private storage.'],
            ['module' => 'Admissions', 'code' => 'admissions.bulk_status', 'name' => 'Приемная комиссия: массовое изменение статуса', 'description' => 'Массовое изменение статуса заявлений.'],
            ['module' => 'Admissions', 'code' => 'admissions.bulk_documents', 'name' => 'Приемная комиссия: массовая отметка документов', 'description' => 'Массовая отметка комплекта документов.'],
            ['module' => 'Admissions', 'code' => 'admissions.bulk_recommend', 'name' => 'Приемная комиссия: массовая рекомендация', 'description' => 'Массовая рекомендация к зачислению.'],
            ['module' => 'Admissions', 'code' => 'admissions.bulk_assign', 'name' => 'Приемная комиссия: массовое назначение направления', 'description' => 'Массовое назначение конкурса или программы.'],
            ['module' => 'Admissions', 'code' => 'admissions.bulk_export', 'name' => 'Приемная комиссия: массовый экспорт', 'description' => 'Экспорт выбранных заявлений.'],
            ['module' => 'Admissions', 'code' => 'admissions.bulk_enroll', 'name' => 'Приемная комиссия: массовое зачисление', 'description' => 'Массовое зачисление абитуриентов в студенты.'],
            ['module' => 'Admissions', 'code' => 'admissions.documents.view', 'name' => 'Документы абитуриентов: просмотр', 'description' => 'Просмотр реестра документов заявления.'],
            ['module' => 'Admissions', 'code' => 'admissions.documents.receive', 'name' => 'Документы абитуриентов: прием', 'description' => 'Отметка документов как полученных.'],
            ['module' => 'Admissions', 'code' => 'admissions.documents.upload', 'name' => 'Документы абитуриентов: загрузка файлов', 'description' => 'Загрузка файлов документов в private storage.'],
            ['module' => 'Admissions', 'code' => 'admissions.documents.verify', 'name' => 'Документы абитуриентов: подтверждение', 'description' => 'Проверка и подтверждение документов.'],
            ['module' => 'Admissions', 'code' => 'admissions.documents.reject', 'name' => 'Документы абитуриентов: отклонение', 'description' => 'Отклонение документа с причиной.'],
            ['module' => 'Admissions', 'code' => 'admissions.documents.delete', 'name' => 'Документы абитуриентов: удаление', 'description' => 'Удаление записей и файлов документов.'],
            ['module' => 'Admissions', 'code' => 'admissions.documents.download', 'name' => 'Документы абитуриентов: скачивание', 'description' => 'Скачивание файлов документов через защищенный endpoint.'],
            ['module' => 'Admissions', 'code' => 'admissions.reference.view', 'name' => 'Приемная комиссия: справочники просмотр', 'description' => 'Просмотр системных справочников приемной комиссии.'],
            ['module' => 'Admissions', 'code' => 'admissions.reference.manage', 'name' => 'Приемная комиссия: справочники управление', 'description' => 'Будущее управление справочниками приемной комиссии через защищенный admin flow.'],
            ['module' => 'Curricula', 'code' => 'curricula.view', 'name' => 'Учебные планы: просмотр', 'description' => 'Просмотр учебных планов.'],
            ['module' => 'Curricula', 'code' => 'curricula.edit', 'name' => 'Учебные планы: ведение', 'description' => 'Создание, изменение и импорт учебных планов.'],
            ['module' => 'Curricula', 'code' => 'curricula.subjects.view', 'name' => 'Учебные планы: дисциплины просмотр', 'description' => 'Просмотр нормализованных дисциплин учебного плана.'],
            ['module' => 'Curricula', 'code' => 'curricula.subjects.create', 'name' => 'Учебные планы: дисциплины добавление', 'description' => 'Добавление дисциплин семестра в учебный план.'],
            ['module' => 'Curricula', 'code' => 'curricula.subjects.update', 'name' => 'Учебные планы: дисциплины изменение', 'description' => 'Изменение часов и контроля дисциплин учебного плана.'],
            ['module' => 'Curricula', 'code' => 'curricula.subjects.delete', 'name' => 'Учебные планы: дисциплины удаление', 'description' => 'Удаление дисциплин из учебного плана.'],
            ['module' => 'Teaching Load', 'code' => 'teachingload.view', 'name' => 'Нагрузка: просмотр', 'description' => 'Просмотр нагрузки преподавателей.'],
            ['module' => 'Teaching Load', 'code' => 'teachingload.edit', 'name' => 'Нагрузка: ведение', 'description' => 'Создание, изменение и импорт нагрузки.'],
            ['module' => 'Teaching Load', 'code' => 'teaching_load.generate', 'name' => 'Нагрузка: генерация', 'description' => 'Формирование нагрузки из учебного плана.'],
            ['module' => 'Teaching Load', 'code' => 'teaching_load.assign', 'name' => 'Нагрузка: назначение преподавателя', 'description' => 'Назначение преподавателя строке нагрузки.'],
            ['module' => 'Teaching Load', 'code' => 'teaching_load.bulk_assign', 'name' => 'Нагрузка: массовое назначение', 'description' => 'Массовое назначение преподавателя строкам нагрузки.'],
            ['module' => 'Teaching Load', 'code' => 'teaching_load.view_coverage', 'name' => 'Нагрузка: покрытие часов', 'description' => 'Просмотр покрытия плановых часов.'],
            ['module' => 'Exams', 'code' => 'exams.view', 'name' => 'Экзамены: просмотр', 'description' => 'Просмотр экзаменов и ГИА.'],
            ['module' => 'Exams', 'code' => 'exams.edit', 'name' => 'Экзамены: ведение', 'description' => 'Создание, изменение и импорт экзаменов.'],
            ['module' => 'Graduation', 'code' => 'graduation.view', 'name' => 'Выпуск: просмотр', 'description' => 'Просмотр выпускников и дипломов.'],
            ['module' => 'Graduation', 'code' => 'graduation.edit', 'name' => 'Выпуск: ведение', 'description' => 'Создание и изменение выпускников, дипломов и приложений.'],
            ['module' => 'FRDO', 'code' => 'frdo.view', 'name' => 'ФРДО: просмотр', 'description' => 'Просмотр пакетов ФРДО.'],
            ['module' => 'FRDO', 'code' => 'frdo.export', 'name' => 'ФРДО: экспорт', 'description' => 'Подготовка, проверка и экспорт пакетов ФРДО.'],
            ['module' => 'FIS', 'code' => 'fis.view', 'name' => 'ФИС: просмотр', 'description' => 'Просмотр пакетов ФИС.'],
            ['module' => 'FIS', 'code' => 'fis.export', 'name' => 'ФИС: экспорт', 'description' => 'Подготовка, проверка и экспорт пакетов ФИС.'],
            ['module' => 'FIS', 'code' => 'fis.outbound.view', 'name' => 'ФИС outbound: просмотр', 'description' => 'Просмотр исходящих официальных пакетов ФИС.'],
            ['module' => 'FIS', 'code' => 'fis.outbound.create', 'name' => 'ФИС outbound: создание', 'description' => 'Создание исходящих пакетов ФИС.'],
            ['module' => 'FIS', 'code' => 'fis.outbound.generate', 'name' => 'ФИС outbound: формирование', 'description' => 'Формирование XML исходящего пакета.'],
            ['module' => 'FIS', 'code' => 'fis.outbound.validate', 'name' => 'ФИС outbound: XSD-валидация', 'description' => 'Проверка пакета по официальной XSD.'],
            ['module' => 'FIS', 'code' => 'fis.outbound.send_test', 'name' => 'ФИС outbound: тестовая отправка', 'description' => 'Отправка в тестовый контур ФИС.'],
            ['module' => 'FIS', 'code' => 'fis.outbound.send_production', 'name' => 'ФИС outbound: production отправка', 'description' => 'Контролируемая production-отправка после отдельной активации.'],
            ['module' => 'FIS', 'code' => 'fis.outbound.status', 'name' => 'ФИС outbound: статус', 'description' => 'Получение статуса обработки исходящего пакета.'],
            ['module' => 'FIS', 'code' => 'fis.outbound.download', 'name' => 'ФИС outbound: скачать XML', 'description' => 'Скачивание XML из private storage.'],
            ['module' => 'FIS', 'code' => 'fis.settings.manage', 'name' => 'ФИС: настройки подключения', 'description' => 'Управление настройками официального подключения ФИС.'],
            ['module' => 'Identity', 'code' => 'gate.scan', 'name' => 'Проходная: сканирование', 'description' => 'Сканирование QR на проходной.'],
            ['module' => 'Identity', 'code' => 'gate.reports', 'name' => 'Проходная: отчеты', 'description' => 'Просмотр событий, отчетов и списка находящихся в здании.'],
            ['module' => 'Identity', 'code' => 'gate.points.manage', 'name' => 'Проходная: корпуса и точки прохода', 'description' => 'Ведение справочника корпусов и точек прохода.'],
            ['module' => 'Identity', 'code' => 'digitalpasses.manage', 'name' => 'Цифровые пропуска: управление', 'description' => 'Выпуск, отзыв и просмотр QR-пропусков.'],

            ['module' => 'HR', 'code' => 'hr.employees.view', 'name' => 'Кадры: сотрудники просмотр', 'description' => 'Просмотр сотрудников.'],
            ['module' => 'HR', 'code' => 'hr.employees.create', 'name' => 'Кадры: сотрудники создание', 'description' => 'Прием сотрудников.'],
            ['module' => 'HR', 'code' => 'hr.employees.update', 'name' => 'Кадры: сотрудники изменение', 'description' => 'Изменение карточки сотрудника.'],
            ['module' => 'HR', 'code' => 'hr.employees.dismiss', 'name' => 'Кадры: увольнение', 'description' => 'Оформление увольнения.'],
            ['module' => 'HR', 'code' => 'hr.employees.digital_pass.issue', 'name' => 'Кадры: выпуск пропуска сотрудника', 'description' => 'Явный выпуск и перевыпуск цифрового пропуска сотрудника.'],
            ['module' => 'HR', 'code' => 'hr.assignments.manage', 'name' => 'Кадры: назначения', 'description' => 'Назначения, переводы, ставки.'],
            ['module' => 'HR', 'code' => 'hr.statuses.manage', 'name' => 'Кадры: статусы', 'description' => 'Отпуска, больничные, командировки и периоды статусов.'],
            ['module' => 'HR', 'code' => 'hr.departments.manage', 'name' => 'Кадры: подразделения', 'description' => 'Управление подразделениями.'],
            ['module' => 'HR', 'code' => 'hr.positions.manage', 'name' => 'Кадры: должности', 'description' => 'Управление должностями.'],
            ['module' => 'HR', 'code' => 'hr.documents.view', 'name' => 'Кадры: документы просмотр', 'description' => 'Просмотр кадровых документов.'],
            ['module' => 'HR', 'code' => 'hr.reports.view', 'name' => 'Кадры: отчеты', 'description' => 'Просмотр кадровых отчетов.'],
            ['module' => 'HR', 'code' => 'hr.calendar.view', 'name' => 'Кадры: календарь просмотр', 'description' => 'Просмотр календаря отсутствий.'],
            ['module' => 'HR', 'code' => 'hr.calendar.manage', 'name' => 'Кадры: календарь управление', 'description' => 'Управление кадровым календарем.'],
            ['module' => 'HR', 'code' => 'hr.absences.manage', 'name' => 'Кадры: отсутствия', 'description' => 'Preview/apply отпусков, больничных, командировок и отстранений.'],
            ['module' => 'HR', 'code' => 'hr.dismissals.manage', 'name' => 'Кадры: увольнения', 'description' => 'Управление периодами увольнения.'],
            ['module' => 'HR', 'code' => 'hr.replacements.view', 'name' => 'Кадры: замены просмотр', 'description' => 'Просмотр кандидатов и затронутых занятий.'],
            ['module' => 'HR', 'code' => 'hr.replacements.manage', 'name' => 'Кадры: замены управление', 'description' => 'Назначение замен преподавателей.'],
            ['module' => 'System', 'code' => 'users.manage', 'name' => 'Пользователи: управление', 'description' => 'Управление пользователями.'],
            ['module' => 'System', 'code' => 'roles.manage', 'name' => 'Роли: управление', 'description' => 'Управление ролями.'],
            ['module' => 'System', 'code' => 'permissions.manage', 'name' => 'Разрешения: управление', 'description' => 'Управление матрицей разрешений.'],
            ['module' => 'System', 'code' => 'settings.manage', 'name' => 'Настройки: управление', 'description' => 'Управление настройками колледжа.'],
            ['module' => 'System', 'code' => 'audit.view', 'name' => 'Аудит: просмотр', 'description' => 'Просмотр журнала аудита.'],
            ['module' => 'UAT', 'code' => 'uat.manage', 'name' => 'UAT: управление', 'description' => 'Управление закрытым пользовательским тестированием и реестром замечаний.'],
            ['module' => 'System', 'code' => 'reference.manage', 'name' => 'Справочники: управление', 'description' => 'Управление нормативно-справочной информацией.'],
            ['module' => 'System', 'code' => 'import.manage', 'name' => 'Импорт: управление', 'description' => 'Универсальный импорт и демо-данные.'],
            ['module' => 'System', 'code' => 'ui.foundation.view', 'name' => 'UI Foundation: просмотр', 'description' => 'Просмотр витрины UI-компонентов.'],
            ['module' => 'Mobile', 'code' => 'mobile.student.view', 'name' => 'Мобильный кабинет студента', 'description' => 'Доступ к мобильному кабинету студента.'],
            ['module' => 'Mobile', 'code' => 'mobile.student.pass', 'name' => 'Мобильный QR студента', 'description' => 'Просмотр мобильного QR-пропуска.'],
            ['module' => 'Legacy', 'code' => 'view_own_data', 'name' => 'Просмотр личных данных', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'view_reports', 'name' => 'Просмотр отчетов', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'manage_users', 'name' => 'Управление пользователями', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'manage_dictionaries', 'name' => 'Управление справочниками', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'manage_schedule', 'name' => 'Управление расписанием', 'description' => 'Legacy permission для совместимости.'],
            ['module' => 'Legacy', 'code' => 'manage_journal', 'name' => 'Ведение журнала', 'description' => 'Legacy permission для совместимости.'],
        ];
    }

    private function directorPermissions(): array
    {
        return [
            'dashboard.view', 'people.view', 'students.view', 'groups.view', 'teachers.view', 'subjects.view', 'classrooms.view',
            'schedule.view', 'schedule.view_conflicts', 'schedule.view_coverage', 'journal.view', 'journal.view_all', 'journal.export', 'attendance.view', 'attendance.reports',
            'admissions.view', 'admissions.applicant.view', 'admissions.application.view', 'admissions.choice.view', 'admissions.document.view', 'admissions.document.download_sensitive', 'admissions.documents.view', 'admissions.documents.download', 'admissions.bulk_export', 'admissions.reference.view', 'curricula.view', 'curricula.subjects.view', 'teachingload.view', 'teaching_load.view_coverage', 'exams.view', 'graduation.view',
            'frdo.view', 'fis.view', 'fis.outbound.view', 'fis.outbound.status', 'gate.reports', 'audit.view', 'reference.manage', 'uat.manage', 'hr.employees.view', 'hr.calendar.view', 'hr.replacements.view', 'hr.reports.view', 'view_reports',
        ];
    }

    /**
     * Учебная часть 1 — расписание, замены, нагрузка и учебные планы. Контингент
     * и журнал доступны только на просмотр там, где без них нельзя составить
     * расписание.
     */
    private function studySchedulePermissions(): array
    {
        return [
            'dashboard.view', 'view_own_data', 'view_reports',
            'schedule.view', 'schedule.create', 'schedule.update', 'schedule.delete', 'schedule.validate',
            'schedule.manage_templates', 'schedule.manage_replacements', 'schedule.view_conflicts', 'schedule.view_coverage', 'manage_schedule',
            'teachingload.view', 'teachingload.edit', 'teaching_load.generate', 'teaching_load.assign', 'teaching_load.bulk_assign', 'teaching_load.view_coverage',
            'curricula.view', 'curricula.edit', 'curricula.subjects.view', 'curricula.subjects.create', 'curricula.subjects.update', 'curricula.subjects.delete',
            'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
            'classrooms.view', 'classrooms.create', 'classrooms.update', 'classrooms.delete', 'manage_dictionaries',
            'exams.view', 'exams.edit',
            'groups.view', 'teachers.view', 'students.view', 'people.view',
            'attendance.view',
            'hr.calendar.view', 'hr.replacements.view', 'hr.replacements.manage',
        ];
    }

    /**
     * Учебная часть 2 — контингент, журнал успеваемости, посещаемость и выпуск.
     * Расписание и нагрузка доступны только на просмотр.
     */
    private function studyRecordsPermissions(): array
    {
        return [
            'dashboard.view', 'view_own_data', 'view_reports',
            'people.view', 'people.update', 'people.link',
            'students.view', 'students.create', 'students.update', 'students.delete',
            'students.bulk_group', 'students.bulk_status', 'students.bulk_course', 'students.bulk_education', 'students.bulk_passes', 'students.bulk_accounts', 'students.bulk_archive', 'students.bulk_export',
            'groups.view', 'groups.create', 'groups.update', 'groups.delete',
            'journal.view', 'journal.view_all', 'journal.edit', 'journal.attendance', 'journal.grades', 'journal.files',
            'journal.complete', 'journal.sign', 'journal.reopen', 'journal.export', 'manage_journal',
            'attendance.view', 'attendance.reports',
            'graduation.view', 'graduation.edit', 'frdo.view',
            'exams.view', 'curricula.view', 'schedule.view', 'teachingload.view',
            'teachers.view', 'subjects.view', 'classrooms.view',
        ];
    }

    private function academicEditorPermissions(): array
    {
        return [
            'dashboard.view', 'people.view', 'students.view', 'students.create', 'students.update', 'students.delete', 'students.bulk_group', 'students.bulk_status', 'students.bulk_course', 'students.bulk_education', 'students.bulk_passes', 'students.bulk_accounts', 'students.bulk_archive', 'students.bulk_export',
            'groups.view', 'groups.create', 'groups.update', 'groups.delete',
            'teachers.view', 'teachers.create', 'teachers.update', 'teachers.delete',
            'subjects.view', 'subjects.create', 'subjects.update', 'subjects.delete',
            'classrooms.view', 'classrooms.create', 'classrooms.update', 'classrooms.delete',
            'schedule.view', 'schedule.create', 'schedule.update', 'schedule.delete', 'schedule.validate', 'schedule.manage_templates', 'schedule.manage_replacements', 'schedule.view_conflicts', 'schedule.view_coverage', 'journal.view', 'journal.edit', 'journal.attendance', 'journal.grades', 'journal.files', 'journal.complete', 'journal.sign', 'journal.reopen', 'journal.view_all', 'journal.export',
            'attendance.view', 'attendance.reports', 'admissions.applicant.view', 'admissions.application.view', 'admissions.choice.view', 'admissions.document.view', 'admissions.documents.view', 'admissions.reference.view', 'curricula.view', 'curricula.edit', 'curricula.subjects.view', 'curricula.subjects.create', 'curricula.subjects.update', 'curricula.subjects.delete',
            'teachingload.view', 'teachingload.edit', 'teaching_load.generate', 'teaching_load.assign', 'teaching_load.bulk_assign', 'teaching_load.view_coverage', 'exams.view', 'exams.edit',
            'graduation.view', 'graduation.edit', 'people.update', 'people.link', 'frdo.view', 'fis.view', 'fis.outbound.view', 'fis.outbound.create', 'fis.outbound.generate', 'fis.outbound.validate', 'fis.outbound.send_test', 'fis.outbound.status', 'reference.manage', 'import.manage',
            'manage_dictionaries', 'manage_schedule', 'manage_journal', 'uat.manage', 'hr.employees.view', 'hr.calendar.view', 'hr.statuses.manage', 'hr.replacements.view', 'hr.replacements.manage', 'hr.reports.view', 'view_reports',
        ];
    }

    /**
     * `people.view` кадрам намеренно не выдаётся: реестр людей — это ещё и
     * студенты с абитуриентами, а кадрам они не нужны. Дубли при этом не
     * появляются: `HrService::resolvePerson` сам находит существующего человека
     * по ФИО и контактам и заводит нового только тогда, когда совпадений нет.
     */
    private function hrPermissions(): array
    {
        return ['dashboard.view', 'hr.employees.view', 'hr.employees.create', 'hr.employees.update', 'hr.employees.dismiss', 'hr.employees.digital_pass.issue', 'hr.assignments.manage', 'hr.statuses.manage', 'hr.departments.manage', 'hr.positions.manage', 'hr.documents.view', 'hr.calendar.view', 'hr.calendar.manage', 'hr.absences.manage', 'hr.dismissals.manage', 'hr.replacements.view', 'hr.replacements.manage', 'hr.reports.view', 'teachers.view', 'gate.reports', 'view_own_data'];
    }

    private function admissionPermissions(): array
    {
        return ['dashboard.view', 'people.view', 'people.create', 'people.update', 'admissions.view', 'admissions.edit', 'admissions.applicant.view', 'admissions.applicant.manage', 'admissions.applicant.create', 'admissions.applicant.update', 'admissions.applicant.archive', 'admissions.application.view', 'admissions.application.create', 'admissions.application.update', 'admissions.application.register', 'admissions.application.manage', 'admissions.choice.view', 'admissions.choice.create', 'admissions.choice.update', 'admissions.choice.delete', 'admissions.document.view', 'admissions.document.create', 'admissions.document.update', 'admissions.document.delete', 'admissions.document.verify', 'admissions.document.download_sensitive', 'admissions.documents.view', 'admissions.documents.receive', 'admissions.documents.upload', 'admissions.documents.verify', 'admissions.documents.reject', 'admissions.documents.download', 'admissions.bulk_status', 'admissions.bulk_documents', 'admissions.bulk_recommend', 'admissions.bulk_assign', 'admissions.bulk_export', 'admissions.bulk_enroll', 'admissions.reference.view', 'students.view', 'groups.view', 'fis.outbound.view', 'fis.outbound.create', 'fis.outbound.generate', 'fis.outbound.validate', 'fis.outbound.send_test', 'fis.outbound.status', 'reference.manage', 'import.manage', 'view_reports'];
    }

    private function teacherPermissions(): array
    {
        return ['dashboard.view', 'hr.calendar.view', 'schedule.view', 'journal.view', 'journal.edit', 'journal.attendance', 'journal.grades', 'journal.files', 'journal.complete', 'journal.sign', 'journal.export', 'attendance.view', 'exams.view', 'view_own_data', 'manage_journal'];
    }

    private function studentPermissions(): array
    {
        return ['dashboard.view', 'schedule.view', 'attendance.view', 'mobile.student.view', 'mobile.student.pass', 'view_own_data'];
    }

    private function securityPermissions(): array
    {
        return ['dashboard.view', 'gate.scan', 'gate.reports', 'gate.points.manage', 'digitalpasses.manage', 'attendance.view', 'attendance.reports', 'view_reports'];
    }

    private function ids(array $codes)
    {
        return Permission::whereIn('code', $codes)->pluck('id');
    }

    private function syncPermissions(string $roleCode, $permissionIds): void
    {
        Role::where('code', $roleCode)->first()?->permissions()->sync($permissionIds);
    }
}
