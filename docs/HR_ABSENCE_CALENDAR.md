# HR Absence Calendar

## Назначение

Кадровый календарь показывает периоды отсутствия сотрудников и связывает их с расписанием. Источник доступности сотрудника — HR Domain, а Schedule Engine использует эти данные только как предупреждение и не отменяет занятия автоматически.

## Маршруты

- UI: `/hr/calendar`
- API: `GET /api/hr/calendar`
- Preview периода: `POST /api/hr/employees/{employee}/status-periods/preview`
- Apply периода: `POST /api/hr/employees/{employee}/status-periods/apply`
- Cancel периода: `POST /api/hr/status-periods/{period}/cancel`
- Затронутые занятия: `GET /api/hr/status-periods/{period}/affected-lessons`
- CSV report: `GET /api/hr/reports/absences.csv`

## Тип периода и состояние периода

Тип отсутствия хранится в `status`: `vacation`, `sick_leave`, `business_trip`, `maternity_leave`, `suspended`, `dismissed`.

Lifecycle периода хранится отдельно в `period_status`: `planned`, `active`, `completed`, `cancelled`.

Эти поля нельзя смешивать: отпуск может быть запланированным, активным, завершенным или отмененным.

## Preview / Apply

Любое кадровое действие проходит через preview. Preview показывает blocking и warning конфликты, а также количество занятий преподавателя, попадающих в период. Apply создает период, пишет Audit и внутренние HR events.

## Конфликты

Blocking:
- пересечение с другим неотмененным кадровым периодом;
- дата увольнения раньше даты приема.

Warning:
- открытый период без даты окончания;
- на дату начала уже есть активный кадровый статус другого типа.

## Internal events

Создаются foundation events: `hr_period_created`, `teacher_unavailable`, `replacement_required`, `replacement_assigned`, `hr_period_cancelled`. Они подготовлены для будущего Notification Center.
