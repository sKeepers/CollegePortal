#!/usr/bin/env bash
# Обход разделов портала под каждой ролью.
#
# Проверяет две вещи разом:
#   - роль, которой раздел показан в меню, открывает все запросы этого раздела.
#     Отказ по вспомогательному запросу закрывает экран целиком — так уже было
#     с журналом аудита у директора;
#   - роль, которой раздел не показан, получает 403. Иначе доступ есть, но не
#     виден — ровно то, чем были плохи legacy-права-«зонтики».
#
# Ожидание берётся не из головы, а из прав самой роли: скрипт спрашивает
# `/api/auth/me` и сравнивает право показа раздела с фактическим ответом.
#
# Право в третьей колонке: запятая — «или», плюс — «и». Так же складываются
# проверки у маршрута.
#
# **Таблица — не украшение, она и есть охват.** 16.08.2026 обход рапортовал
# «715 запросов, 0 расхождений» ровно столько же дней подряд, сколько таблицу не
# трогали: за это время прибавились кураторская группа, отчёты по группам,
# заявки на правку журнала, отчёты кадров, конфликты и покрытие расписания,
# настройки, резервные копии и приёмка UAT — 46 точек входа мимо проверки.
# Добавили раздел — допишите строку, иначе «расхождений нет» будет означать
# «мы там не смотрели».
#
# Чего здесь нет намеренно: разделы, открытые каждому вошедшему (`auth/me`,
# `account/*`, `dashboard/layouts`, `public/*`, `settings/public`) — у модели
# «право есть → 200, права нет → 403» для них нет ожидания; и диагностики шлюза
# ФИС (`fis/outbound/gateway/*`) — они ходят на внешний узел, и обход зависел бы
# от чужой сети.
#
# После `SEC-002` вход отдаёт токен в httpOnly cookie, а не в теле ответа,
# поэтому здесь cookie jar (`curl -c/-b`), а не заголовок Authorization.
#
# Использование:
#   bash scripts/deploy/check-role-access.sh [базовый-адрес] [пароль]
#
# Пароль по умолчанию берётся из DEMO_USER_PASSWORD. В репозитории он не хранится.

set -uo pipefail

BASE="${1:-https://127.0.0.1:5443/api}"
PASSWORD="${2:-${DEMO_USER_PASSWORD:-}}"

if [[ -z "$PASSWORD" ]]; then
    echo "Пароль не задан: передайте вторым аргументом или через DEMO_USER_PASSWORD." >&2
    exit 2
fi

# Учётные записи стенда, по одной на роль. Состав — docs/TEST_USERS.md.
ACCOUNTS='
admin@local
director@local
deputy@local
study@local
study.records@local
admission@local
hr@local
teacher@local
curator@local
student@local
security@local
'

# раздел|право показа раздела|endpoints через запятую
#
# Право взято из пункта меню в AppLayout.vue, endpoints — из запросов, которые
# страница делает при открытии. Несколько прав через запятую значат ИЛИ — ровно
# как в объявлении у маршрута: свой пропуск и свою нагрузку человек видит по
# `view_own_data`, не имея права на чужие.
SECTIONS='
Люди|people.view|people
Студенты|students.view|students,students/export
Группы|groups.view|groups,groups/export
Расписание|schedule.view|schedule-lessons,schedule/entries
Журнал|journal.view|journal/lessons
Посещаемость|attendance.reports|attendance/teachers/today,attendance/students/today,dashboard/analytics/executive
Учебные планы|curricula.view|curricula,curricula/export
Нагрузка|teachingload.view|teaching-loads/export
Своя нагрузка|teachingload.view,view_own_data|teaching-loads
Экзамены и ГИА|exams.view|exams,exams/export
Выпускники|graduation.view|graduates,graduates/export
ФРДО|frdo.view|frdo-packages
ФИС|fis.view|fis-packages
ФИС обмен|fis.outbound.view|fis/outbound/packages,fis/outbound/spec-info
Специальности|reference.view|specialties,specialties/export
Образовательные программы|reference.view|education-programs,education-programs/export
Преподаватели|teachers.view|teachers,teachers/export
Дисциплины|subjects.view|subjects,subjects/export
Аудитории|classrooms.view|classrooms,classrooms/export
Приёмная комиссия|admissions.application.view|admissions/applications,admissions/applicants
Заявления|admissions.view|applicant-applications,applicant-applications/export,admissions/stats
Сотрудники|hr.employees.view|employees,departments,positions
Кадровый календарь|hr.calendar.view|hr/calendar
Подразделения и должности|hr.employees.view|departments,positions
Кто в здании|gate.reports|access/muster
Отчёты проходной|gate.reports|access/reports/summary,access/reports/events
Корпуса и точки|gate.reports|access/buildings,access/points
Цифровые пропуска|digitalpasses.manage,view_own_data|digital-identities
Моя группа|journal.view|curator/groups
Оценки студента|journal.view|grades
Отметки студента|journal.view|attendance
Мобильный кабинет студента|mobile.student.view|mobile/student
Мобильный кабинет преподавателя|mobile.teacher.view|mobile/teacher
Мобильный кабинет куратора|mobile.curator.view|mobile/curator
Мобильный кабинет администратора|mobile.admin.view|mobile/admin
Импорт данных|import.manage|admin/import/config,admin/import/history
Управление данными|demo_data.manage|admin/demo-data
Пользователи|users.manage|admin/users,admin/users/roles
Роли|roles.manage|admin/roles
Разрешения|permissions.manage|admin/permissions,admin/permissions/roles/list
Аудит|audit.view|admin/audit
Корзина|trash.manage|trash,deletion-requests/pending,deletion-requests
Журнал: заявки на правку|journal.view+journal.reopen|journal/edit-requests/pending,journal/edit-requests/history
Журнал: выгрузки|journal.view+journal.export|journal/export/group.csv,journal/export/teacher.csv
Моя группа|journal.view|curator/groups
Отметки и оценки|journal.view|attendance,grades
Отчёты по группам|journal.view|reports/attendance-by-group,reports/grades-by-group
Отчёты по группам: выгрузка|journal.export|reports/attendance-by-group/export,reports/grades-by-group/export
История посещаемости|attendance.reports|attendance/history
События проходной|gate.reports|access/events
Расписание: выгрузка|schedule.view|schedule-lessons/export
Расписание: конфликты|schedule.view+schedule.view_conflicts|schedule/conflicts
Расписание: покрытие часов|schedule.view+schedule.view_coverage|schedule/coverage
Расписание: шаблоны|schedule.view+schedule.manage_templates|schedule/templates
Кадровые отчёты|hr.calendar.view+hr.reports.view|hr/reports/absences,hr/reports/absences.csv
Сотрудники: выгрузка|hr.employees.view|employees/export
Справочники приёмной|admissions.reference.view|admissions/reference
Заявления: сводка|admissions.view|applicant-applications/stats
Полнота карточек|students.view|students/card-completeness/summary
Настройки|settings.manage|admin/settings
Резервные копии|settings.manage|admin/database-backups
Справочники|reference.view|admin/reference/catalogs,admin/reference/items
Пользователи: люди|users.manage|admin/users/people
Управление данными: выгрузка|demo_data.manage|admin/demo-data/export
Приёмка UAT|uat.manage|admin/uat/config,admin/uat/runs,admin/uat/feedback
Приёмка UAT: выгрузки|uat.manage|admin/uat/export/results.csv,admin/uat/export/feedback.csv
'

problems=0
checked=0

for login in $ACCOUNTS; do
    jar="$(mktemp)"

    code=$(curl -sk -c "$jar" -o /dev/null -w '%{http_code}' -X POST "$BASE/auth/login" \
        -H 'Content-Type: application/json' \
        -d "{\"login\":\"$login\",\"password\":\"$PASSWORD\"}")

    if [[ "$code" != "200" ]]; then
        echo "ВХОД  $login — вход не удался ($code)"
        problems=$((problems + 1))
        rm -f "$jar"
        continue
    fi

    held=$(curl -sk -b "$jar" "$BASE/auth/me" | tr ',' '\n' | sed -n 's/.*"\([a-z_][a-z_.]*\)".*/\1/p')
    is_admin=$(curl -sk -b "$jar" "$BASE/auth/me" | grep -c '"code":"admin"')

    while IFS='|' read -r section gate endpoints; do
        [[ -z "$section" ]] && continue

        expected=deny

        if [[ "$is_admin" != "0" ]]; then
            expected=allow
        else
            # Запятая — «или», плюс — «и»: ровно так складываются проверки у
            # маршрута. Несколько `permission:` на одном маршруте требуют всех
            # разом, альтернативы внутри одной перечисляются через запятую.
            IFS=',' read -ra gates <<< "$gate"
            for one in "${gates[@]}"; do
                enough=1
                IFS='+' read -ra needed <<< "$one"
                for need in "${needed[@]}"; do
                    printf '%s\n' "$held" | grep -qx "$need" || { enough=0; break; }
                done

                if [[ "$enough" == 1 ]]; then
                    expected=allow
                    break
                fi
            done
        fi

        IFS=',' read -ra paths <<< "$endpoints"
        for path in "${paths[@]}"; do
            status=$(curl -sk -b "$jar" -o /dev/null -w '%{http_code}' "$BASE/$path")
            checked=$((checked + 1))

            if [[ "$status" =~ ^5 ]]; then
                # Падение сервера — это не «доступ есть». Пока смотрели только на
                # 403, ответ 500 у роли с правом читался как разрешение, и обход
                # рапортовал бы «расхождений нет» о сломанном разделе.
                echo "ОШИБКА   $login  $section  GET $path — $status"
                problems=$((problems + 1))
            elif [[ "$expected" == allow && "$status" == "403" ]]; then
                echo "ЗАКРЫТО  $login  $section  GET $path — 403, хотя право $gate есть"
                problems=$((problems + 1))
            elif [[ "$expected" == deny && "$status" != "403" ]]; then
                echo "ОТКРЫТО  $login  $section  GET $path — $status, хотя права $gate нет"
                problems=$((problems + 1))
            fi
        done
    done <<< "$SECTIONS"

    curl -sk -b "$jar" -o /dev/null -X POST "$BASE/auth/logout" \
        -H "X-CSRF-Token: $(sed -n 's/.*cp_csrf[[:space:]]*//p' "$jar" | tail -1)" || true
    rm -f "$jar"
done

echo
echo "Проверено запросов: $checked. Расхождений: $problems."

[[ "$problems" -eq 0 ]] || exit 1
