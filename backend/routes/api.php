<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountIdentityController;
use App\Http\Controllers\Api\AccountNotificationController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\AdminUserIdentityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccessGateController;
use App\Http\Controllers\Api\AccessPointController;
use App\Http\Controllers\Api\AccessReportController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Controllers\Api\AdminPermissionController;
use App\Http\Controllers\Api\AdminSettingController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\CuratorGroupController;
use App\Http\Controllers\Api\Admissions\AdmissionApplicationController as AdmissionsAdmissionApplicationController;
use App\Http\Controllers\Api\Admissions\AdmissionDocumentFileController as AdmissionsAdmissionDocumentFileController;
use App\Http\Controllers\Api\Admissions\ApplicationDocumentController as AdmissionsApplicationDocumentController;
use App\Http\Controllers\Api\Admissions\ApplicantController as AdmissionsApplicantController;
use App\Http\Controllers\Api\Admissions\ApplicantSnilsController as AdmissionsApplicantSnilsController;
use App\Http\Controllers\Api\Admissions\AdmissionReferenceController;
use App\Http\Controllers\Api\Admissions\DocumentReadinessController as AdmissionsDocumentReadinessController;
use App\Http\Controllers\Api\Admissions\EducationDocumentController as AdmissionsEducationDocumentController;
use App\Http\Controllers\Api\Admissions\IdentityDocumentController as AdmissionsIdentityDocumentController;
use App\Http\Controllers\Api\Admissions\ProgramChoiceController as AdmissionsProgramChoiceController;
use App\Http\Controllers\Api\ApplicantApplicationController;
use App\Http\Controllers\Api\ApplicantDocumentController;
use App\Http\Controllers\Api\AdmissionBulkController;
use App\Http\Controllers\Api\BuildingController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\Hr\PersonMatchController;
use App\Http\Controllers\Api\HrCalendarController;
use App\Http\Controllers\Api\MobileAdminController;
use App\Http\Controllers\Api\MobileCuratorController;
use App\Http\Controllers\Api\MobileStudentController;
use App\Http\Controllers\Api\MobileTeacherController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PersonPhotoController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceAnalysisController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\DeletionRequestController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DigitalIdentityController;
use App\Http\Controllers\Api\DormAbsenceController;
use App\Http\Controllers\Api\DormLeaveController;
use App\Http\Controllers\Api\DormPlacementController;
use App\Http\Controllers\Api\DormRoomController;
use App\Http\Controllers\Api\RfidCardController;
use App\Http\Controllers\Api\DemoDataController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\DashboardLayoutController;
use App\Http\Controllers\Api\DatabaseBackupController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\FrdoPackageController;
use App\Http\Controllers\Api\FisDictionaryIntakeController;
use App\Http\Controllers\Api\FisPackageController;
use App\Http\Controllers\Api\FisOutboundPackageController;
use App\Http\Controllers\Api\FisAdmissionsImportController;
use App\Http\Controllers\Api\EducationProgramController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\JournalLessonController;
use App\Http\Controllers\Api\GraduateController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentBulkController;
use App\Http\Controllers\Api\StudentDocumentController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\ScheduleLessonController;
use App\Http\Controllers\Api\ScheduleEngineController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReferenceCatalogController;
use App\Http\Controllers\Api\ReferenceItemController;
use App\Http\Controllers\Api\SpecialtyController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\UniversalImportController;
use App\Http\Controllers\Api\TeachingLoadController;
use App\Http\Controllers\Api\UatController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:auth.login');
// Вход через внешний способ и список подключённых способов — открыты без входа
// намеренно: кнопку надо нарисовать и нажать до того, как человек опознан.
Route::get('auth/providers', [AuthController::class, 'providers']);
Route::post('auth/provider-login', [AuthController::class, 'loginWithProvider'])->middleware('throttle:auth.external');

// Вход по коду из бота. Запрос кода и его проверка считаются разными счётчиками:
// первый защищает от рассылки кодов чужим людям, второй — от подбора шести цифр.
Route::post('auth/code/request', [AuthController::class, 'requestCode'])->middleware('throttle:auth.code.request');
Route::post('auth/code/login', [AuthController::class, 'loginWithCode'])->middleware('throttle:auth.code.login');
Route::get('settings/public', [AdminSettingController::class, 'publicSettings']);
Route::get('public/specialties', [SpecialtyController::class, 'index']);
Route::get('public/education-programs', [EducationProgramController::class, 'index']);

// `api.csrf` стоит после `api.token` намеренно: проверять происхождение имеет смысл
// только для запроса, который уже опознан, и только когда токен пришёл из cookie.
Route::middleware(['api.token', 'api.csrf', 'throttle:api.authenticated'])->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    // Раздел «Моя учётная запись» — без права: своей почтой, телефоном и паролем
    // распоряжается любой вошедший, независимо от роли.
    Route::get('account', [AccountController::class, 'show']);
    Route::patch('account/contacts', [AccountController::class, 'updateContacts']);
    Route::post('account/password', [AccountController::class, 'changePassword']);
    Route::get('account/identities', [AccountIdentityController::class, 'index']);
    Route::post('account/identities', [AccountIdentityController::class, 'store']);
    Route::delete('account/identities/{identity}', [AccountIdentityController::class, 'destroy']);
    // Галочки уведомлений — без права, как и остальной раздел: своими уведомлениями
    // человек распоряжается сам.
    Route::get('account/notifications', [AccountNotificationController::class, 'index']);
    Route::post('account/notifications', [AccountNotificationController::class, 'update']);
    Route::post('account/notifications/link-code', [AccountNotificationController::class, 'linkCode']);
    // Мобильные кабинеты. Право решает видимость раздела, а не то, чьи данные
    // видно: кабинет отдаёт только данные вошедшего — преподаватель находится
    // по `teachers.user_id`, и чужой день не открывается подстановкой
    // параметра. У студенческого кабинета серверной проверки не было вовсе:
    // право `mobile.student.view` существовало, но спрашивал его только
    // маршрутизатор фронтенда.
    Route::get('mobile/student', [MobileStudentController::class, 'show'])
        ->middleware('permission:mobile.student.view');
    Route::get('mobile/teacher', [MobileTeacherController::class, 'show'])
        ->middleware('permission:mobile.teacher.view');
    // Право открывает раздел всем кураторам сразу и потому ничего не
    // разграничивает: чья это группа, проверяет сам контроллер по
    // `groups.curator_id` на каждом запросе.
    Route::middleware('permission:mobile.curator.view')->group(function (): void {
        Route::get('mobile/curator', [MobileCuratorController::class, 'index']);
        Route::get('mobile/curator/groups/{group}', [MobileCuratorController::class, 'group']);
        Route::get('mobile/curator/groups/{group}/attendance', [MobileCuratorController::class, 'attendance']);
        Route::get('mobile/curator/groups/{group}/access', [MobileCuratorController::class, 'access']);
    });
    // Счётчики кабинета администратора. Сами входящие собираются из тех же
    // маршрутов, что и «колокольчик» на десктопе, и каждый из них проверяет
    // своё право сам.
    Route::get('mobile/admin', [MobileAdminController::class, 'show'])
        ->middleware('permission:mobile.admin.view');
    // Своя группа глазами куратора. Маршруты общие для компьютера и телефона:
    // владелец попросил одну и ту же картину на обоих экранах, а два расчёта
    // одной успеваемости однажды покажут два разных средних балла.
    //
    // Право взято то же, по которому куратор видит журнал: ничего сверх этого
    // здесь не открывается, а чью группу видно — решает `groups.curator_id` в
    // самом контроллере. Кто видит журнал целиком, видит здесь любую группу.
    Route::middleware('permission:journal.view')->group(function (): void {
        Route::get('curator/groups', [CuratorGroupController::class, 'index']);
        Route::get('curator/groups/{group}/students', [CuratorGroupController::class, 'students']);
        Route::get('curator/groups/{group}/performance', [CuratorGroupController::class, 'performance']);
    });
    Route::get('dashboard/layouts', [DashboardLayoutController::class, 'index']);
    Route::post('dashboard/layouts', [DashboardLayoutController::class, 'store']);
    Route::post('dashboard/layouts/reset', [DashboardLayoutController::class, 'reset']);
    Route::put('dashboard/layouts/{dashboardLayout}', [DashboardLayoutController::class, 'update']);
    Route::delete('dashboard/layouts/{dashboardLayout}', [DashboardLayoutController::class, 'destroy']);
    Route::post('dashboard/layouts/{dashboardLayout}/activate', [DashboardLayoutController::class, 'activate']);
    Route::post('uat/feedback', [UatController::class, 'storeFeedback']);

    Route::middleware('permission:uat.manage')->group(function (): void {
        Route::get('admin/uat/config', [UatController::class, 'config']);
        Route::get('admin/uat/runs', [UatController::class, 'runs']);
        Route::post('admin/uat/runs', [UatController::class, 'storeRun']);
        Route::get('admin/uat/runs/{run}', [UatController::class, 'showRun']);
        Route::post('admin/uat/runs/{run}/complete', [UatController::class, 'completeRun']);
        Route::post('admin/uat/runs/{run}/results/{result}', [UatController::class, 'updateResult']);
        Route::get('admin/uat/results/{result}/screenshot', [UatController::class, 'downloadResultScreenshot']);
        Route::get('admin/uat/feedback', [UatController::class, 'feedback']);
        Route::put('admin/uat/feedback/{feedback}', [UatController::class, 'updateFeedback']);
        Route::get('admin/uat/feedback/{feedback}/screenshot', [UatController::class, 'downloadFeedbackScreenshot']);
        Route::get('admin/uat/export/results.csv', [UatController::class, 'exportRuns']);
        Route::get('admin/uat/export/feedback.csv', [UatController::class, 'exportIssues']);
    });


    Route::middleware('permission:hr.employees.view')->group(function (): void {
        Route::get('employees', [EmployeeController::class, 'index']);
        // Строго до employees/{employee}: иначе параметр перехватит слово export.
        Route::get('employees/export', [EmployeeController::class, 'export']);
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:hr.employees.create');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:hr.employees.update');
        Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:hr.employees.update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:hr.employees.dismiss');
        Route::post('employees/{employee}/digital-pass', [EmployeeController::class, 'issueDigitalPass'])->middleware('permission:hr.employees.digital_pass.issue');
        Route::post('employees/{employee}/assignments', [EmployeeController::class, 'storeAssignment'])->middleware('permission:hr.assignments.manage');
        Route::put('employee-assignments/{assignment}', [EmployeeController::class, 'updateAssignment'])->middleware('permission:hr.assignments.manage');
        Route::patch('employee-assignments/{assignment}', [EmployeeController::class, 'updateAssignment'])->middleware('permission:hr.assignments.manage');
        Route::delete('employee-assignments/{assignment}', [EmployeeController::class, 'destroyAssignment'])->middleware('permission:hr.assignments.manage');
        Route::post('employees/{employee}/status-periods', [EmployeeController::class, 'storeStatusPeriod'])->middleware('permission:hr.statuses.manage');
        Route::put('employee-status-periods/{period}', [EmployeeController::class, 'updateStatusPeriod'])->middleware('permission:hr.statuses.manage');
        Route::patch('employee-status-periods/{period}', [EmployeeController::class, 'updateStatusPeriod'])->middleware('permission:hr.statuses.manage');
        Route::delete('employee-status-periods/{period}', [EmployeeController::class, 'destroyStatusPeriod'])->middleware('permission:hr.statuses.manage');
    });
    // `HR-002`: кадровик спрашивает, кого портал нашёл по его же вводу. Не реестр
    // людей, а результат поиска, и полей ровно на выбор между двумя. Право своё,
    // чтобы этот узкий взгляд в общий реестр был виден в матрице разрешений, а не
    // прятался внутри права на заведение сотрудника.
    // `people.view` перечислено через ИЛИ не для кадров, а для тех, у кого реестр
    // и так открыт: узкий срез им тем более разрешён, и вопрос «который из этих
    // двух» получает правильный ответ у всех ролей, а не только у кадровой.
    Route::post('hr/person-matches', PersonMatchController::class)
        ->middleware('permission:hr.people.match,people.view');

    Route::middleware('permission:hr.calendar.view')->group(function (): void {
        Route::get('hr/calendar', [HrCalendarController::class, 'calendar']);
        Route::get('hr/reports/absences', [HrCalendarController::class, 'report'])->middleware('permission:hr.reports.view');
        Route::get('hr/reports/absences.csv', [HrCalendarController::class, 'export'])->middleware('permission:hr.reports.view');
        Route::get('hr/status-periods/{period}/affected-lessons', [HrCalendarController::class, 'affectedLessons']);
        Route::get('hr/replacements/candidates/{scheduleEntry}/{employee}', [HrCalendarController::class, 'candidates'])->middleware('permission:hr.replacements.view');
    });
    Route::middleware('permission:hr.absences.manage')->group(function (): void {
        Route::post('hr/employees/{employee}/status-periods/preview', [HrCalendarController::class, 'previewPeriod']);
        Route::post('hr/employees/{employee}/status-periods/apply', [HrCalendarController::class, 'applyPeriod']);
        Route::post('hr/status-periods/{period}/cancel', [HrCalendarController::class, 'cancelPeriod']);
    });
    Route::middleware('permission:hr.replacements.manage')->group(function (): void {
        Route::post('hr/replacements/preview', [HrCalendarController::class, 'replacementPreview']);
        Route::post('hr/replacements/apply', [HrCalendarController::class, 'replacementApply']);
    });
    Route::middleware('permission:hr.employees.view')->group(function (): void {
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::get('positions', [PositionController::class, 'index']);
    });
    Route::middleware('permission:hr.departments.manage')->group(function (): void {
        Route::post('departments', [DepartmentController::class, 'store']);
        Route::put('departments/{department}', [DepartmentController::class, 'update']);
        Route::patch('departments/{department}', [DepartmentController::class, 'update']);
        Route::delete('departments/{department}', [DepartmentController::class, 'destroy']);
    });
    Route::middleware('permission:hr.positions.manage')->group(function (): void {
        Route::post('positions', [PositionController::class, 'store']);
        Route::put('positions/{position}', [PositionController::class, 'update']);
        Route::patch('positions/{position}', [PositionController::class, 'update']);
        Route::delete('positions/{position}', [PositionController::class, 'destroy']);
    });

    Route::middleware('permission:people.view')->group(function (): void {
        Route::get('people', [PersonController::class, 'index']);
        Route::post('people/duplicates/check', [PersonController::class, 'duplicateCheck']);
        // Разбор и слияние объявлены до `people/{person}`: иначе слово `merge`
        // перехватилось бы как идентификатор человека.
        Route::post('people/merge/preview', [PersonController::class, 'mergePreview'])->middleware('permission:people.update');
        Route::post('people/merge', [PersonController::class, 'merge'])->middleware('permission:people.update');
        Route::get('people/{person}', [PersonController::class, 'show']);
        Route::get('people/{person}/profiles', [PersonController::class, 'profiles']);
    });
    Route::post('people', [PersonController::class, 'store'])->middleware('permission:people.create');
    Route::patch('people/{person}', [PersonController::class, 'update'])->middleware('permission:people.update');

    // Управленческая сводка и отчёты посещаемости.
    //
    // До 10.08.2026 группа стояла под правом-зонтиком `view_reports`, а
    // требуемое право выводилось из префикса: для сводки получалось
    // `dashboard.view` — то самое, что есть у каждого вошедшего. Проверено на
    // стенде: студент и преподаватель открывали управленческую сводку. Владелец
    // решил закрыть её тем же правом, что и соседние отчёты.
    Route::middleware('permission:attendance.reports')->group(function (): void {
        Route::get('dashboard/analytics/executive', [DashboardAnalyticsController::class, 'executive']);
        Route::get('attendance/teachers/today', [AttendanceAnalysisController::class, 'teachersToday']);
        Route::get('attendance/students/today', [AttendanceAnalysisController::class, 'studentsToday']);
        Route::get('attendance/history', [AttendanceAnalysisController::class, 'history']);
        Route::get('attendance/person/{type}/{id}/summary', [AttendanceAnalysisController::class, 'personSummary']);
        Route::get('attendance/person/{type}/{id}/days', [AttendanceAnalysisController::class, 'personDays']);
    });

    Route::middleware('permission:admissions.reference.view')->group(function (): void {
        Route::get('admissions/reference', [AdmissionReferenceController::class, 'index']);
        Route::get('admissions/reference/{catalog}', [AdmissionReferenceController::class, 'show']);
    });

    Route::middleware('permission:admissions.applicant.view')->group(function (): void {
        Route::get('admissions/applicants', [AdmissionsApplicantController::class, 'index']);
        Route::get('admissions/applicants/{id}', [AdmissionsApplicantController::class, 'show'])->whereNumber('id');
    });
    Route::post('admissions/applicants', [AdmissionsApplicantController::class, 'store'])
        ->middleware('permission:admissions.applicant.create');
    Route::patch('admissions/applicants/{applicant}', [AdmissionsApplicantController::class, 'update'])
        ->whereNumber('applicant')
        ->middleware('permission:admissions.applicant.update');
    Route::post('admissions/applicants/{applicant}/archive', [AdmissionsApplicantController::class, 'archive'])
        ->whereNumber('applicant')
        ->middleware('permission:admissions.applicant.archive');
    Route::patch('admissions/applicants/{applicant}/snils', [AdmissionsApplicantSnilsController::class, 'update'])
        ->whereNumber('applicant')
        ->middleware('permission:admissions.document.update');

    Route::get('admissions/applications', [AdmissionsAdmissionApplicationController::class, 'index'])
        ->middleware('permission:admissions.application.view');
    Route::get('admissions/applications/{application}', [AdmissionsAdmissionApplicationController::class, 'show'])
        ->whereNumber('application')
        ->middleware('permission:admissions.application.view');
    // Готовность заявления к выгрузке в ФИС. Право то же, что у просмотра: это
    // не действие, а взгляд на карточку глазами схемы ФИС.
    Route::get('admissions/applications/{application}/fis-readiness', [AdmissionsAdmissionApplicationController::class, 'fisReadiness'])
        ->whereNumber('application')
        ->middleware('permission:admissions.application.view');
    Route::post('admissions/applications', [AdmissionsAdmissionApplicationController::class, 'store'])
        ->middleware('permission:admissions.application.create');
    Route::patch('admissions/applications/{application}', [AdmissionsAdmissionApplicationController::class, 'update'])
        ->whereNumber('application')
        ->middleware('permission:admissions.application.update');
    Route::post('admissions/applications/{application}/register', [AdmissionsAdmissionApplicationController::class, 'register'])
        ->whereNumber('application')
        ->middleware('permission:admissions.application.register');
    Route::get('admissions/applications/{application}/choices', [AdmissionsProgramChoiceController::class, 'index'])
        ->whereNumber('application')
        ->middleware('permission:admissions.choice.view');
    Route::post('admissions/applications/{application}/choices', [AdmissionsProgramChoiceController::class, 'store'])
        ->whereNumber('application')
        ->middleware('permission:admissions.choice.create');
    Route::get('admissions/applications/{application}/documents', [AdmissionsApplicationDocumentController::class, 'show'])
        ->whereNumber('application')
        ->middleware('permission:admissions.document.view');
    Route::put('admissions/applications/{application}/identity-document', [AdmissionsApplicationDocumentController::class, 'assignIdentity'])
        ->whereNumber('application')
        ->middleware('permission:admissions.document.update');
    Route::put('admissions/applications/{application}/education-document', [AdmissionsApplicationDocumentController::class, 'assignEducation'])
        ->whereNumber('application')
        ->middleware('permission:admissions.document.update');
    Route::patch('admissions/choices/{choice}', [AdmissionsProgramChoiceController::class, 'update'])
        ->whereNumber('choice')
        ->middleware('permission:admissions.choice.update');
    Route::delete('admissions/choices/{choice}', [AdmissionsProgramChoiceController::class, 'destroy'])
        ->whereNumber('choice')
        ->middleware('permission:admissions.choice.delete');
    Route::get('admissions/applicants/{applicant}/identity-documents', [AdmissionsIdentityDocumentController::class, 'index'])
        ->whereNumber('applicant')
        ->middleware('permission:admissions.document.view');
    Route::post('admissions/applicants/{applicant}/identity-documents', [AdmissionsIdentityDocumentController::class, 'store'])
        ->whereNumber('applicant')
        ->middleware('permission:admissions.document.create');
    Route::get('admissions/identity-documents/{document}', [AdmissionsIdentityDocumentController::class, 'show'])
        ->whereNumber('document')
        ->middleware('permission:admissions.document.view');
    Route::patch('admissions/identity-documents/{document}', [AdmissionsIdentityDocumentController::class, 'update'])
        ->whereNumber('document')
        ->middleware('permission:admissions.document.update');
    Route::delete('admissions/identity-documents/{document}', [AdmissionsIdentityDocumentController::class, 'destroy'])
        ->whereNumber('document')
        ->middleware('permission:admissions.document.delete');
    Route::get('admissions/applicants/{applicant}/education-documents', [AdmissionsEducationDocumentController::class, 'index'])
        ->whereNumber('applicant')
        ->middleware('permission:admissions.document.view');
    Route::post('admissions/applicants/{applicant}/education-documents', [AdmissionsEducationDocumentController::class, 'store'])
        ->whereNumber('applicant')
        ->middleware('permission:admissions.document.create');
    Route::get('admissions/education-documents/{document}', [AdmissionsEducationDocumentController::class, 'show'])
        ->whereNumber('document')
        ->middleware('permission:admissions.document.view');
    Route::patch('admissions/education-documents/{document}', [AdmissionsEducationDocumentController::class, 'update'])
        ->whereNumber('document')
        ->middleware('permission:admissions.document.update');
    Route::delete('admissions/education-documents/{document}', [AdmissionsEducationDocumentController::class, 'destroy'])
        ->whereNumber('document')
        ->middleware('permission:admissions.document.delete');
    Route::post('admissions/identity-documents/{document}/files', [AdmissionsAdmissionDocumentFileController::class, 'uploadIdentity'])
        ->whereNumber('document')
        ->middleware('permission:admissions.document.update');
    Route::post('admissions/education-documents/{document}/files', [AdmissionsAdmissionDocumentFileController::class, 'uploadEducation'])
        ->whereNumber('document')
        ->middleware('permission:admissions.document.update');
    Route::get('admissions/document-files/{file}/download', [AdmissionsAdmissionDocumentFileController::class, 'download'])
        ->whereNumber('file')
        ->middleware('permission:admissions.document.download_sensitive');
    Route::delete('admissions/document-files/{file}', [AdmissionsAdmissionDocumentFileController::class, 'destroy'])
        ->whereNumber('file')
        ->middleware('permission:admissions.document.delete');
    Route::get('admissions/applications/{application}/document-readiness', [AdmissionsDocumentReadinessController::class, 'show'])
        ->whereNumber('application')
        ->middleware('permission:admissions.document.view');

    // Полнота карточки студента и его документы. Право указано явно, поэтому таблица
    // префиксов в EnsurePermission здесь не участвует; литеральный сегмент объявлен
    // раньше параметрического, иначе `{student}` перехватил бы слово.
    Route::get('students/card-completeness/summary', [StudentDocumentController::class, 'summary'])
        ->middleware('permission:students.view');
    Route::get('students/{student}/card-completeness', [StudentDocumentController::class, 'completeness'])
        ->whereNumber('student')
        ->middleware('permission:students.view');
    Route::get('students/{student}/documents', [StudentDocumentController::class, 'index'])
        ->whereNumber('student')
        ->middleware('permission:students.view');
    Route::post('students/{student}/identity-documents', [StudentDocumentController::class, 'storeIdentity'])
        ->whereNumber('student')
        ->middleware('permission:students.update');
    Route::post('students/{student}/education-documents', [StudentDocumentController::class, 'storeEducation'])
        ->whereNumber('student')
        ->middleware('permission:students.update');

    Route::post('admin/users/provision', [AdminUserController::class, 'provision'])
        ->middleware('permission:users.manage');

    Route::post('admin/users/reset-password', [AdminUserController::class, 'resetPassword'])
        ->middleware('permission:users.manage');

    // Удаление в два шага. Пометить карточку может тот, кто её ведёт;
    // решает и чистит корзину только администратор.
    Route::post('deletion-requests', [DeletionRequestController::class, 'store'])
        ->middleware('permission:trash.request');
    // Что уйдёт вместе с карточкой. Спрашивает тот же, кто помечает, — право то же.
    Route::get('deletion-requests/dependents', [DeletionRequestController::class, 'dependents'])
        ->middleware('permission:trash.request');
    Route::get('deletion-requests/pending', [DeletionRequestController::class, 'pending'])
        ->middleware('permission:trash.manage');
    Route::get('deletion-requests', [DeletionRequestController::class, 'index'])
        ->middleware('permission:trash.manage');
    Route::post('deletion-requests/{deletionRequest}/approve', [DeletionRequestController::class, 'approve'])
        ->middleware('permission:trash.manage');
    Route::post('deletion-requests/{deletionRequest}/reject', [DeletionRequestController::class, 'reject'])
        ->middleware('permission:trash.manage');
    Route::get('trash', [DeletionRequestController::class, 'trash'])
        ->middleware('permission:trash.manage');
    Route::post('trash/{type}/{id}/restore', [DeletionRequestController::class, 'restore'])
        ->middleware('permission:trash.manage');
    Route::delete('trash/{type}/{id}', [DeletionRequestController::class, 'purge'])
        ->middleware('permission:trash.manage');

    Route::middleware('permission:audit.view')->group(function (): void {
        Route::get('admin/audit', [AuditLogController::class, 'index']);
        Route::get('admin/audit/{auditLog}', [AuditLogController::class, 'show']);
    });

    Route::middleware('permission:permissions.manage')->group(function (): void {
        Route::apiResource('admin/permissions', AdminPermissionController::class)->except(['show']);
        Route::post('admin/permissions/{permission}/roles', [AdminPermissionController::class, 'assignRoles']);
        Route::get('admin/permissions/roles/list', [AdminPermissionController::class, 'roles']);
    });

    Route::middleware('permission:roles.manage')->group(function (): void {
        Route::apiResource('admin/roles', AdminRoleController::class)->except(['show']);
    });

    Route::middleware('permission:users.manage')->group(function (): void {
        // Строго до apiResource: иначе {user} перехватит слова roles и people.
        Route::get('admin/users/roles', [AdminUserController::class, 'roles']);
        Route::get('admin/users/people', [AdminUserController::class, 'people']);
        Route::post('admin/users/{user}/roles', [AdminUserController::class, 'assignRoles']);
        // Отвязка чужого способа входа — работа с учётной записью, поэтому
        // право то же. Своего права `AUTH-005` намеренно не заводила.
        Route::get('admin/users/{user}/identities', [AdminUserIdentityController::class, 'index']);
        Route::delete('admin/users/{user}/identities/{identity}', [AdminUserIdentityController::class, 'destroy']);
        // Исполнитель распоряжения директора: снять чужую подписку на уведомления.
        // Право то же, что у работы с учётной записью; след пишется в журнал аудита.
        Route::get('admin/users/{user}/notifications', [AdminNotificationController::class, 'index']);
        Route::delete('admin/users/{user}/notifications/{subscription}', [AdminNotificationController::class, 'destroy']);
        Route::post('admin/users/{user}/block', [AdminUserController::class, 'block']);
        Route::post('admin/users/{user}/unblock', [AdminUserController::class, 'unblock']);
        Route::apiResource('admin/users', AdminUserController::class);
    });

    Route::middleware('permission:settings.manage')->group(function (): void {
        Route::get('admin/settings', [AdminSettingController::class, 'index']);
        Route::put('admin/settings', [AdminSettingController::class, 'update']);
        Route::get('admin/database-backups', [DatabaseBackupController::class, 'index']);
        Route::post('admin/database-backups', [DatabaseBackupController::class, 'store']);
        Route::post('admin/database-backups/{snapshot}/restore', [DatabaseBackupController::class, 'restore']);
    });

    // Ниже — то, что до 10.08.2026 лежало под правом-зонтиком
    // `manage_dictionaries`, а требуемое право выводилось из префикса URL и
    // метода по таблице `EnsurePermission::DOMAIN_RULES`. Таблицы больше нет:
    // право написано у маршрута.
    //
    // Правило переноса было одно — дословность: право взято у того же кода,
    // который вычислял его раньше. Где на маршруте уже стояло собственное
    // право, выведенное сохранено рядом: две проверки складываются через И, и
    // отбросить внешнюю значило бы расширить доступ.

    // Справочники: перечни подписей. Читает тот, кто видит их в фильтрах,
    // правит владелец справочников.
    Route::apiResource('admin/reference/catalogs', ReferenceCatalogController::class)
        ->parameters(['catalogs' => 'catalog'])->except(['show'])
        ->middlewareFor('index', 'permission:reference.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:reference.manage');
    Route::apiResource('admin/reference/items', ReferenceItemController::class)
        ->parameters(['items' => 'item'])->except(['show'])
        ->middlewareFor('index', 'permission:reference.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:reference.manage');

    // Загрузка данных из файлов.
    Route::middleware('permission:import.manage')->group(function (): void {
        Route::get('admin/import/config', [UniversalImportController::class, 'config']);
        Route::get('admin/import/history', [UniversalImportController::class, 'history']);
        Route::get('admin/import/templates/{dataType}.csv', [UniversalImportController::class, 'template']);
        Route::get('admin/import/templates/{dataType}.xlsx', [UniversalImportController::class, 'xlsxTemplate']);
        Route::post('admin/import/fis-admissions/analyze', [FisAdmissionsImportController::class, 'analyze']);
        Route::post('admin/import/fis-admissions/dry-run', [FisAdmissionsImportController::class, 'dryRun']);
        Route::post('admin/import/fis-admissions/apply', [FisAdmissionsImportController::class, 'apply']);
        Route::get('admin/import/fis-admissions/jobs/{importJob}', [FisAdmissionsImportController::class, 'show']);
        Route::post('admin/import/preview', [UniversalImportController::class, 'preview']);
        Route::post('admin/import/{importJob}/validate', [UniversalImportController::class, 'validateJob']);
        Route::post('admin/import/{importJob}/confirm', [UniversalImportController::class, 'confirm']);
    });

    // Разведено с импортом 10.08.2026. `import.manage` открывало и загрузку
    // файлов, и очистку рабочих данных стенда — «загрузить студентов» и
    // «стереть базу» под одним ключом. Теперь очистка отдельно и только у
    // администратора.
    Route::middleware('permission:demo_data.manage')->group(function (): void {
        Route::get('admin/demo-data', [DemoDataController::class, 'status']);
        Route::post('admin/demo-data/create', [DemoDataController::class, 'create']);
        Route::post('admin/demo-data/clear', [DemoDataController::class, 'clear']);
        Route::post('admin/demo-data/reset', [DemoDataController::class, 'reset']);
        Route::post('admin/demo-data/import', [DemoDataController::class, 'import']);
        Route::get('admin/demo-data/export', [DemoDataController::class, 'export']);
    });

    // Фото человека правит тот, кто ведёт его карточку, поэтому право зависит
    // от типа. Раньше тип был параметром, право выводилось из префикса URL, и
    // промах по одному типу отнимал у роли её собственную карточку: в таблице
    // стояло `alumni`, а контроллер принимает `graduates`.
    //
    // Тип вынесен в путь, а не оставлен параметром с ограничением: маршруты с
    // одинаковым URI перекрывают друг друга в коллекции, и выжил бы только
    // последний из трёх. В контроллер тип приходит через `defaults`.
    foreach (['students' => 'students.update', 'teachers' => 'teachers.update', 'graduates' => 'graduation.edit'] as $photoType => $photoPermission) {
        Route::post('person-photos/'.$photoType.'/{id}', [PersonPhotoController::class, 'store'])
            ->defaults('type', $photoType)->middleware('permission:'.$photoPermission);
        Route::delete('person-photos/'.$photoType.'/{id}', [PersonPhotoController::class, 'destroy'])
            ->defaults('type', $photoType)->middleware('permission:'.$photoPermission);
    }

    // Аудитории. Выгрузка отдана праву на просмотр — решение владельца
    // 10.08.2026: кто видит список на экране, тот может сохранить его в файл.
    Route::get('classrooms/export', [ClassroomController::class, 'export'])->middleware('permission:classrooms.view');
    Route::post('classrooms/import', [ClassroomController::class, 'import'])->middleware('permission:classrooms.update');
    Route::apiResource('classrooms', ClassroomController::class)
        ->middlewareFor(['index', 'show'], 'permission:classrooms.view')
        ->middlewareFor('store', 'permission:classrooms.create')
        ->middlewareFor('update', 'permission:classrooms.update')
        ->middlewareFor('destroy', 'permission:classrooms.delete');

    // Проходная. Отчёты читает тот, кто и так их видит; справочник корпусов и
    // точек правит только его владелец.
    Route::post('access/scan', [AccessGateController::class, 'scan'])->middleware('permission:gate.scan');
    Route::middleware('permission:gate.reports')->group(function (): void {
        Route::get('access/events', [AccessGateController::class, 'events']);
        Route::get('access/reports/summary', [AccessReportController::class, 'summary']);
        Route::get('access/reports/events', [AccessReportController::class, 'events']);
        Route::get('access/muster', [AccessReportController::class, 'muster']);
        Route::get('access/buildings', [BuildingController::class, 'index']);
        Route::get('access/points', [AccessPointController::class, 'index']);
    });
    Route::middleware('permission:gate.points.manage')->group(function (): void {
        Route::post('access/buildings', [BuildingController::class, 'store']);
        Route::put('access/buildings/{building}', [BuildingController::class, 'update']);
        Route::delete('access/buildings/{building}', [BuildingController::class, 'destroy']);
        Route::post('access/points', [AccessPointController::class, 'store']);
        Route::put('access/points/{access_point}', [AccessPointController::class, 'update']);
        Route::delete('access/points/{access_point}', [AccessPointController::class, 'destroy']);
    });

    // RFID-карты. Ведёт комендант: заводит, выдаёт, принимает, блокирует.
    // Выдача и приём — отдельные действия, а не правка поля: портал записывает,
    // кому и когда, иначе учёт ничем не отличается от списка.
    // Общежитие: комнаты и заселение. Ведёт комендант общежития, заместитель по
    // воспитательной работе смотрит.
    Route::get('dorm/rooms', [DormRoomController::class, 'index'])
        ->middleware('permission:dorm.rooms.view');
    Route::post('dorm/rooms', [DormRoomController::class, 'store'])
        ->middleware('permission:dorm.rooms.manage');
    Route::patch('dorm/rooms/{dormRoom}', [DormRoomController::class, 'update'])
        ->middleware('permission:dorm.rooms.manage');
    Route::get('dorm/placements', [DormPlacementController::class, 'index'])
        ->middleware('permission:dorm.placements.view');
    Route::post('dorm/placements', [DormPlacementController::class, 'store'])
        ->middleware('permission:dorm.placements.manage');
    Route::post('dorm/placements/relocate', [DormPlacementController::class, 'relocate'])
        ->middleware('permission:dorm.placements.manage');
    Route::post('dorm/placements/move-out', [DormPlacementController::class, 'moveOut'])
        ->middleware('permission:dorm.placements.manage');
    Route::get('dorm/leaves', [DormLeaveController::class, 'index'])
        ->middleware('permission:dorm.absences.view');
    Route::post('dorm/leaves', [DormLeaveController::class, 'store'])
        ->middleware('permission:dorm.leaves.manage');
    Route::delete('dorm/leaves/{dormLeave}', [DormLeaveController::class, 'destroy'])
        ->middleware('permission:dorm.leaves.manage');
    Route::get('dorm/absences', [DormAbsenceController::class, 'index'])
        ->middleware('permission:dorm.absences.view');
    // Пересчёт — работа коменданта: он же ведёт отлучки, из-за которых ночь и
    // приходится пересчитывать. Заместитель список читает, но не пересчитывает.
    Route::post('dorm/absences/recalculate', [DormAbsenceController::class, 'recalculate'])
        ->middleware('permission:dorm.leaves.manage');

    Route::get('rfid-cards', [RfidCardController::class, 'index'])
        ->middleware('permission:rfid.cards.view');
    // Объявлены до `rfid-cards/{rfidCard}`: иначе «lookup» и «journal» ушли бы
    // в параметр маршрута и искались бы как карты с такими номерами.
    Route::get('rfid-cards/lookup', [RfidCardController::class, 'lookup'])
        ->middleware('permission:rfid.cards.view');
    Route::get('rfid-cards/people', [RfidCardController::class, 'people'])
        ->middleware('permission:rfid.cards.view');
    Route::get('rfid-cards/journal', [RfidCardController::class, 'journal'])
        ->middleware('permission:rfid.cards.view');
    Route::get('rfid-cards/journal/export', [RfidCardController::class, 'exportJournal'])
        ->middleware('permission:rfid.cards.view');
    Route::get('rfid-cards/groups', [RfidCardController::class, 'groups'])
        ->middleware('permission:rfid.cards.view');
    Route::post('rfid-cards/bind', [RfidCardController::class, 'bind'])
        ->middleware('permission:rfid.cards.manage');
    Route::post('rfid-cards/journal/import', [RfidCardController::class, 'importJournalPreview'])
        ->middleware('permission:rfid.cards.manage');
    Route::post('rfid-cards/journal/import/{importJob}/confirm', [RfidCardController::class, 'importJournalConfirm'])
        ->middleware('permission:rfid.cards.manage');
    Route::post('rfid-cards', [RfidCardController::class, 'store'])
        ->middleware('permission:rfid.cards.manage');
    Route::patch('rfid-cards/{rfidCard}', [RfidCardController::class, 'update'])
        ->middleware('permission:rfid.cards.manage');
    Route::post('rfid-cards/{rfidCard}/issue', [RfidCardController::class, 'issue'])
        ->middleware('permission:rfid.cards.manage');
    Route::post('rfid-cards/{rfidCard}/accept', [RfidCardController::class, 'accept'])
        ->middleware('permission:rfid.cards.manage');
    Route::post('rfid-cards/{rfidCard}/status', [RfidCardController::class, 'status'])
        ->middleware('permission:rfid.cards.manage');
    Route::post('rfid-cards/{rfidCard}/release', [RfidCardController::class, 'release'])
        ->middleware('permission:rfid.cards.manage');
    Route::delete('rfid-cards/{rfidCard}', [RfidCardController::class, 'destroy'])
        ->middleware('permission:rfid.cards.manage');

    // Цифровые пропуска. Список и свой QR открыты ещё и по `view_own_data`:
    // человек видит собственный пропуск, не имея права на чужие. Пустой профиль
    // даёт пустой список, а не отказ, — это забота `DigitalIdentityController`.
    Route::get('digital-identities', [DigitalIdentityController::class, 'index'])
        ->middleware('permission:digitalpasses.manage,view_own_data');
    Route::get('digital-identities/{digitalIdentity}/qr', [DigitalIdentityController::class, 'qr'])
        ->middleware('permission:digitalpasses.manage,view_own_data');
    Route::post('digital-identities/issue', [DigitalIdentityController::class, 'issue'])
        ->middleware('permission:digitalpasses.manage');
    Route::post('digital-identities/{digitalIdentity}/revoke', [DigitalIdentityController::class, 'revoke'])
        ->middleware('permission:digitalpasses.manage');

    // Документы заявления. Второе право сохранено намеренно: работать с
    // документами может тот, кто видит и ведёт сами заявления.
    Route::get('admissions/{applicantApplication}/documents', [ApplicantDocumentController::class, 'index'])->middleware(['permission:admissions.view', 'permission:admissions.documents.view']);
    Route::post('admissions/{applicantApplication}/documents/{type}/receive', [ApplicantDocumentController::class, 'receive'])->middleware(['permission:admissions.edit', 'permission:admissions.documents.receive']);
    Route::post('admissions/{applicantApplication}/documents/{type}/upload', [ApplicantDocumentController::class, 'upload'])->middleware(['permission:admissions.edit', 'permission:admissions.documents.upload']);
    Route::post('admissions/{applicantApplication}/documents/{document}/verify', [ApplicantDocumentController::class, 'verify'])->middleware(['permission:admissions.edit', 'permission:admissions.documents.verify']);
    Route::post('admissions/{applicantApplication}/documents/{document}/reject', [ApplicantDocumentController::class, 'reject'])->middleware(['permission:admissions.edit', 'permission:admissions.documents.reject']);
    Route::put('admissions/{applicantApplication}/documents/{document}', [ApplicantDocumentController::class, 'update'])->middleware(['permission:admissions.edit', 'permission:admissions.documents.receive']);
    Route::delete('admissions/{applicantApplication}/documents/{document}', [ApplicantDocumentController::class, 'destroy'])->middleware(['permission:admissions.edit', 'permission:admissions.documents.delete']);
    Route::get('admissions/{applicantApplication}/documents/{document}/files/{file}/download', [ApplicantDocumentController::class, 'download'])->middleware(['permission:admissions.view', 'permission:admissions.documents.download']);
    Route::delete('admissions/{applicantApplication}/documents/{document}/files/{file}', [ApplicantDocumentController::class, 'destroyFile'])->middleware(['permission:admissions.edit', 'permission:admissions.documents.delete']);

    // Приёмная комиссия. Массовые операции проверяют право на каждое действие
    // внутри `AdmissionBulkController::authorizeAction`, поэтому на входе стоит
    // право просмотра: иначе директор с `admissions.bulk_export` не смог бы
    // выгрузить выборку, которую видит. Уточнение таблицы однажды это уже
    // ломало.
    Route::get('admissions/stats', [ApplicantApplicationController::class, 'stats'])->middleware('permission:admissions.view');
    Route::get('applicant-applications/stats', [ApplicantApplicationController::class, 'stats'])->middleware('permission:admissions.view');
    Route::post('admissions/bulk/preview', [AdmissionBulkController::class, 'preview'])->middleware('permission:admissions.view');
    Route::post('admissions/bulk/apply', [AdmissionBulkController::class, 'apply'])->middleware('permission:admissions.view');
    Route::post('applicant-applications/bulk/preview', [AdmissionBulkController::class, 'preview'])->middleware('permission:admissions.view');
    Route::post('applicant-applications/bulk/apply', [AdmissionBulkController::class, 'apply'])->middleware('permission:admissions.view');
    Route::get('applicant-applications/export', [ApplicantApplicationController::class, 'export'])->middleware('permission:admissions.view');
    Route::post('applicant-applications/import', [ApplicantApplicationController::class, 'import'])->middleware('permission:admissions.edit');
    Route::post('applicant-applications/{applicantApplication}/enroll', [ApplicantApplicationController::class, 'enroll'])->middleware('permission:admissions.edit');
    Route::patch('applicant-applications/{applicantApplication}/documents/{type}', [ApplicantApplicationController::class, 'updateDocument'])->middleware('permission:admissions.edit');
    Route::apiResource('applicant-applications', ApplicantApplicationController::class)
        ->middlewareFor(['index', 'show'], 'permission:admissions.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:admissions.edit');

    // Учебные планы. У дисциплин плана своё право, и право на сам план
    // сохранено рядом: дисциплины плана видит тот, кто видит план.
    Route::get('curricula/export', [CurriculumController::class, 'export'])->middleware('permission:curricula.view');
    Route::post('curricula/import', [CurriculumController::class, 'import'])->middleware('permission:curricula.edit');
    Route::get('curricula/{curriculum}/subjects', [CurriculumController::class, 'subjects'])->middleware(['permission:curricula.view', 'permission:curricula.subjects.view']);
    Route::get('curricula/{curriculum}/semesters', [CurriculumController::class, 'semesters'])->middleware(['permission:curricula.view', 'permission:curricula.subjects.view']);
    Route::get('curricula/{curriculum}/summary', [CurriculumController::class, 'summary'])->middleware(['permission:curricula.view', 'permission:curricula.subjects.view']);
    Route::post('curricula/{curriculum}/subjects', [CurriculumController::class, 'storeSubject'])->middleware(['permission:curricula.edit', 'permission:curricula.subjects.create']);
    Route::put('curriculum-subjects/{curriculumSubject}', [CurriculumController::class, 'updateSubject'])->middleware('permission:curricula.subjects.update');
    Route::delete('curriculum-subjects/{curriculumSubject}', [CurriculumController::class, 'destroySubject'])->middleware('permission:curricula.subjects.delete');
    Route::post('curricula/{curriculum}/items', [CurriculumController::class, 'storeItem'])->middleware('permission:curricula.edit');
    Route::delete('curriculum-items/{curriculumItem}', [CurriculumController::class, 'destroyItem'])->middleware('permission:curricula.edit');
    Route::apiResource('curricula', CurriculumController::class)
        ->middlewareFor(['index', 'show'], 'permission:curricula.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:curricula.edit');

    // Образовательные программы и специальности — справочная часть: читают все,
    // кто ведёт группы и выпуск, правит владелец справочников.
    Route::get('education-programs/export', [EducationProgramController::class, 'export'])->middleware('permission:reference.view');
    Route::post('education-programs/import', [EducationProgramController::class, 'import'])->middleware('permission:reference.manage');
    Route::apiResource('education-programs', EducationProgramController::class)
        ->middlewareFor(['index', 'show'], 'permission:reference.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:reference.manage');

    // Экзамены и ГИА.
    Route::get('exams/export', [ExamController::class, 'export'])->middleware('permission:exams.view');
    Route::post('exams/import', [ExamController::class, 'import'])->middleware('permission:exams.edit');
    Route::post('exams/{exam}/results', [ExamController::class, 'storeResult'])->middleware('permission:exams.edit');
    Route::delete('exam-results/{examResult}', [ExamController::class, 'destroyResult'])->middleware('permission:exams.edit');
    Route::apiResource('exams', ExamController::class)
        ->middlewareFor(['index', 'show'], 'permission:exams.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:exams.edit');

    // Группы.
    Route::get('groups/export', [GroupController::class, 'export'])->middleware('permission:groups.view');
    Route::post('groups/import', [GroupController::class, 'import'])->middleware('permission:groups.update');
    Route::apiResource('groups', GroupController::class)
        ->middlewareFor(['index', 'show'], 'permission:groups.view')
        ->middlewareFor('store', 'permission:groups.create')
        ->middlewareFor('update', 'permission:groups.update')
        // Группа — не карточка человека, и в корзину она не кладётся: мягкого
        // удаления у `groups` нет, а `students.group_id` на неё ссылается.
        // Поэтому пометить группу на удаление нельзя, а удалить её может только
        // администратор. Заводить группы в корзину — отдельное решение владельца.
        ->middlewareFor('destroy', 'permission:trash.manage');

    // Выпуск и дипломы.
    Route::get('graduates/export', [GraduateController::class, 'export'])->middleware('permission:graduation.view');
    Route::post('graduates/import', [GraduateController::class, 'import'])->middleware('permission:graduation.edit');
    Route::post('graduates/{graduate}/diploma', [GraduateController::class, 'storeDiploma'])->middleware('permission:graduation.edit');
    Route::post('graduates/{graduate}/supplement', [GraduateController::class, 'storeSupplement'])->middleware('permission:graduation.edit');
    Route::apiResource('graduates', GraduateController::class)
        ->middlewareFor(['index', 'show'], 'permission:graduation.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:graduation.edit');

    // ФРДО и ФИС: у выгрузки здесь своё право, и оно так и задумано —
    // `frdo.export` и `fis.export` открывают подготовку, проверку и выгрузку.
    Route::post('frdo-packages/{frdoPackage}/validate', [FrdoPackageController::class, 'validatePackage'])->middleware('permission:frdo.export');
    Route::post('frdo-packages/{frdoPackage}/mark-exported', [FrdoPackageController::class, 'markExported'])->middleware('permission:frdo.export');
    Route::post('frdo-packages/{frdoPackage}/archive', [FrdoPackageController::class, 'archive'])->middleware('permission:frdo.export');
    Route::get('frdo-packages/{frdoPackage}/export.csv', [FrdoPackageController::class, 'exportCsv'])->middleware('permission:frdo.export');
    Route::get('frdo-packages/{frdoPackage}/export.json', [FrdoPackageController::class, 'exportJson'])->middleware('permission:frdo.export');
    Route::apiResource('frdo-packages', FrdoPackageController::class)->only(['index', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:frdo.view')
        ->middlewareFor('store', 'permission:frdo.export');

    // Официальный обмен с ФИС ГИА и Приёма.
    //
    // Второе право у шлюзовых вызовов — не замысел, а наследство таблицы: POST
    // выводился в `fis.outbound.create`, и проверка связи требовала права на
    // создание пакета. Сохранено дословно, чтобы перенос ничего не расширил;
    // отдельной строкой вынесено в отчёт как то, что стоит пересмотреть.
    Route::post('fis/dictionaries/preview', [FisDictionaryIntakeController::class, 'preview'])->middleware('permission:fis.outbound.view');
    Route::post('fis/dictionaries/apply', [FisDictionaryIntakeController::class, 'apply'])->middleware(['permission:fis.outbound.view', 'permission:fis.settings.manage']);
    // Диагностика шлюза и обновление статуса больше не требуют права создавать
    // пакеты. `create` досталось им от таблицы префиксов, где POST означал
    // «создание»; при переносе права к маршруту оно сохранялось дословно, чтобы
    // ничего не расширить молча. Владелец снял его 11.08.2026: четыре вызова —
    // чистое чтение из шлюза, пятый проверяет заявление в тестовом контуре, а
    // обновление статуса называет право `fis.outbound.status`. Создание,
    // формирование и отправка остались как были.
    Route::get('fis/outbound/spec-info', [FisOutboundPackageController::class, 'specInfo'])->middleware('permission:fis.outbound.view');
    Route::get('fis/outbound/gateway/health', [FisOutboundPackageController::class, 'gatewayHealth'])->middleware('permission:fis.outbound.view');
    Route::get('fis/outbound/gateway/version', [FisOutboundPackageController::class, 'gatewayVersion'])->middleware('permission:fis.outbound.view');
    Route::post('fis/outbound/gateway/zkspd-check', [FisOutboundPackageController::class, 'gatewayZkspdCheck'])->middleware('permission:fis.outbound.view');
    Route::post('fis/outbound/gateway/dictionaries/list', [FisOutboundPackageController::class, 'gatewayDictionariesList'])->middleware('permission:fis.outbound.view');
    Route::post('fis/outbound/gateway/dictionaries/details', [FisOutboundPackageController::class, 'gatewayDictionaryDetails'])->middleware('permission:fis.outbound.view');
    Route::post('fis/outbound/gateway/institution/info', [FisOutboundPackageController::class, 'gatewayInstitutionInfo'])->middleware('permission:fis.outbound.view');
    Route::post('fis/outbound/gateway/check-application', [FisOutboundPackageController::class, 'gatewayTestCheckApplication'])->middleware('permission:fis.outbound.view');
    Route::get('fis/outbound/packages', [FisOutboundPackageController::class, 'index'])->middleware('permission:fis.outbound.view');
    Route::post('fis/outbound/packages', [FisOutboundPackageController::class, 'store'])->middleware('permission:fis.outbound.create');
    Route::get('fis/outbound/packages/{package}', [FisOutboundPackageController::class, 'show'])->middleware('permission:fis.outbound.view');
    Route::post('fis/outbound/packages/{package}/generate', [FisOutboundPackageController::class, 'generate'])->middleware(['permission:fis.outbound.create', 'permission:fis.outbound.generate']);
    Route::post('fis/outbound/packages/{package}/validate', [FisOutboundPackageController::class, 'validatePackage'])->middleware(['permission:fis.outbound.generate', 'permission:fis.outbound.validate']);
    Route::post('fis/outbound/packages/{package}/send-preview', [FisOutboundPackageController::class, 'sendPreview'])->middleware(['permission:fis.outbound.create', 'permission:fis.outbound.send_test']);
    Route::post('fis/outbound/packages/{package}/send', [FisOutboundPackageController::class, 'send'])->middleware(['permission:fis.outbound.create', 'permission:fis.outbound.send_test']);
    Route::post('fis/outbound/packages/{package}/refresh-status', [FisOutboundPackageController::class, 'refreshStatus'])->middleware('permission:fis.outbound.status');
    Route::post('fis/outbound/packages/{package}/cancel', [FisOutboundPackageController::class, 'cancel'])->middleware(['permission:fis.outbound.create', 'permission:fis.outbound.generate']);
    Route::get('fis/outbound/packages/{package}/events', [FisOutboundPackageController::class, 'events'])->middleware('permission:fis.outbound.view');
    Route::get('fis/outbound/packages/{package}/download', [FisOutboundPackageController::class, 'download'])->middleware(['permission:fis.outbound.view', 'permission:fis.outbound.download']);

    Route::post('fis-packages/{fisPackage}/validate', [FisPackageController::class, 'validatePackage'])->middleware('permission:fis.export');
    Route::post('fis-packages/{fisPackage}/mark-exported', [FisPackageController::class, 'markExported'])->middleware('permission:fis.export');
    Route::post('fis-packages/{fisPackage}/archive', [FisPackageController::class, 'archive'])->middleware('permission:fis.export');
    Route::get('fis-packages/{fisPackage}/export.csv', [FisPackageController::class, 'exportCsv'])->middleware('permission:fis.export');
    Route::get('fis-packages/{fisPackage}/export.json', [FisPackageController::class, 'exportJson'])->middleware('permission:fis.export');
    Route::apiResource('fis-packages', FisPackageController::class)->only(['index', 'store', 'show'])
        ->middlewareFor(['index', 'show'], 'permission:fis.view')
        ->middlewareFor('store', 'permission:fis.export');

    Route::get('specialties/export', [SpecialtyController::class, 'export'])->middleware('permission:reference.view');
    Route::post('specialties/import', [SpecialtyController::class, 'import'])->middleware('permission:reference.manage');
    Route::apiResource('specialties', SpecialtyController::class)
        ->middlewareFor(['index', 'show'], 'permission:reference.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:reference.manage');

    // Студенты. Массовые операции — как у приёмной комиссии: право на просмотр
    // на входе, каждое действие проверяет `StudentBulkController` сам. Решение
    // владельца 10.08.2026; до него на входе стояло право на создание.
    Route::post('students/bulk/preview', [StudentBulkController::class, 'preview'])->middleware('permission:students.view');
    Route::post('students/bulk/apply', [StudentBulkController::class, 'apply'])->middleware('permission:students.view');
    Route::get('students/export', [StudentController::class, 'export'])->middleware('permission:students.view');
    Route::post('students/import', [StudentController::class, 'import'])->middleware('permission:students.update');
    Route::apiResource('students', StudentController::class)
        ->middlewareFor(['index', 'show'], 'permission:students.view')
        ->middlewareFor('store', 'permission:students.create')
        ->middlewareFor('update', 'permission:students.update')
        // Удаление в два шага: карточку помечает тот, кто её ведёт
        // (`POST deletion-requests`), а удаляет администратор. Прямое удаление
        // осталось за `trash.manage` — им же чистится корзина.
        ->middlewareFor('destroy', 'permission:trash.manage');

    Route::get('subjects/export', [SubjectController::class, 'export'])->middleware('permission:subjects.view');
    Route::post('subjects/import', [SubjectController::class, 'import'])->middleware('permission:subjects.update');
    Route::apiResource('subjects', SubjectController::class)
        ->middlewareFor(['index', 'show'], 'permission:subjects.view')
        ->middlewareFor('store', 'permission:subjects.create')
        ->middlewareFor('update', 'permission:subjects.update')
        ->middlewareFor('destroy', 'permission:subjects.delete');

    // Нагрузка.
    Route::post('teaching-load/generate/preview', [TeachingLoadController::class, 'generatePreview'])->middleware('permission:teaching_load.generate');
    Route::post('teaching-load/generate/apply', [TeachingLoadController::class, 'generateApply'])->middleware('permission:teaching_load.generate');
    Route::get('teaching-load/{teachingLoad}/coverage', [TeachingLoadController::class, 'coverage'])->middleware(['permission:teachingload.view', 'permission:teaching_load.view_coverage']);
    Route::post('teaching-load/items/{teachingLoadItem}/assign-teacher', [TeachingLoadController::class, 'assignTeacher'])->middleware('permission:teaching_load.assign');
    Route::post('teaching-load/items/bulk-assign-teacher', [TeachingLoadController::class, 'bulkAssignTeacher'])->middleware(['permission:teaching_load.assign', 'permission:teaching_load.bulk_assign']);
    Route::get('teaching-loads/export', [TeachingLoadController::class, 'export'])->middleware('permission:teachingload.view');
    Route::post('teaching-loads/import', [TeachingLoadController::class, 'import'])->middleware('permission:teachingload.edit');
    Route::post('teaching-loads/{teachingLoad}/items', [TeachingLoadController::class, 'storeItem'])->middleware('permission:teachingload.edit');
    Route::delete('teaching-load-items/{teachingLoadItem}', [TeachingLoadController::class, 'destroyItem'])->middleware('permission:teachingload.edit');
    // Список нагрузки открыт ещё и по `teachingload.view_own`: преподаватель
    // видит свою. Раньше здесь стояло `view_own_data` — право с тем же
    // смыслом, но выданное почти каждой роли, и раздел «Нагрузка»
    // показывался восьми ролям из тринадцати. Пустой профиль даёт пустой
    // список, а не отказ, — это `TeachingLoadController`.
    Route::apiResource('teaching-loads', TeachingLoadController::class)
        ->middlewareFor('index', 'permission:teachingload.view,teachingload.view_own')
        ->middlewareFor('show', 'permission:teachingload.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:teachingload.edit');

    Route::get('teachers/export', [TeacherController::class, 'export'])->middleware('permission:teachers.view');
    Route::post('teachers/import', [TeacherController::class, 'import'])->middleware('permission:teachers.update');
    Route::apiResource('teachers', TeacherController::class)
        ->middlewareFor(['index', 'show'], 'permission:teachers.view')
        ->middlewareFor('store', 'permission:teachers.create')
        ->middlewareFor('update', 'permission:teachers.update')
        // Как у студентов: пометить может ведущий карточку, удаляет администратор.
        ->middlewareFor('destroy', 'permission:trash.manage');

    Route::middleware('permission:schedule.view')->group(function (): void {
        Route::get('schedule/entries', [ScheduleEngineController::class, 'index']);
        Route::get('schedule/templates', [ScheduleEngineController::class, 'templates'])->middleware('permission:schedule.manage_templates');
        Route::post('schedule/templates', [ScheduleEngineController::class, 'storeTemplate'])->middleware('permission:schedule.manage_templates');
        Route::post('schedule/templates/{scheduleTemplate}/apply-preview', [ScheduleEngineController::class, 'templateApplyPreview'])->middleware('permission:schedule.manage_templates');
        Route::post('schedule/templates/{scheduleTemplate}/apply', [ScheduleEngineController::class, 'templateApply'])->middleware('permission:schedule.manage_templates');
        Route::get('schedule/conflicts', [ScheduleEngineController::class, 'conflicts'])->middleware('permission:schedule.view_conflicts');
        Route::get('schedule/coverage', [ScheduleEngineController::class, 'coverage'])->middleware('permission:schedule.view_coverage');
        Route::get('schedule/group/{groupId}', [ScheduleEngineController::class, 'group']);
        Route::get('schedule/teacher/{teacherId}', [ScheduleEngineController::class, 'teacher']);
        Route::get('schedule/classroom/{classroomId}', [ScheduleEngineController::class, 'classroom']);
        Route::post('schedule/preview', [ScheduleEngineController::class, 'preview'])->middleware('permission:schedule.validate');
        Route::post('schedule/validate', [ScheduleEngineController::class, 'validateEntry'])->middleware('permission:schedule.validate');
        Route::post('schedule/apply', [ScheduleEngineController::class, 'apply'])->middleware('permission:schedule.create');
        Route::post('schedule/entries/{scheduleEntry}/replace-teacher', [ScheduleEngineController::class, 'replaceTeacher'])->middleware('permission:schedule.manage_replacements');
        Route::post('schedule/entries/{scheduleEntry}/replace-classroom', [ScheduleEngineController::class, 'replaceClassroom'])->middleware('permission:schedule.manage_replacements');
        Route::post('schedule/entries/{scheduleEntry}/move', [ScheduleEngineController::class, 'move'])->middleware('permission:schedule.manage_replacements');
        Route::post('schedule/entries/{scheduleEntry}/cancel', [ScheduleEngineController::class, 'cancel'])->middleware('permission:schedule.manage_replacements');
        Route::post('schedule/entries/{scheduleEntry}/restore', [ScheduleEngineController::class, 'restore'])->middleware('permission:schedule.manage_replacements');
    });

    // Строго до apiResource: иначе параметр {schedule_lesson} перехватит слово export.
    Route::get('schedule-lessons/export', [ScheduleLessonController::class, 'export'])
        ->middleware('permission:schedule.view');

    Route::apiResource('schedule-lessons', ScheduleLessonController::class)
        ->middlewareFor(['index', 'show'], 'permission:schedule.view')
        ->middlewareFor(['store', 'update', 'destroy'], 'permission:schedule.update');

    Route::middleware('permission:journal.view')->group(function (): void {
        Route::get('journal/lessons', [JournalLessonController::class, 'index']);
        Route::get('journal/export/group.csv', [JournalLessonController::class, 'exportGroup'])->middleware('permission:journal.export');
        Route::get('journal/export/teacher.csv', [JournalLessonController::class, 'exportTeacher'])->middleware('permission:journal.export');
        Route::get('journal/lessons/{lesson}', [JournalLessonController::class, 'show']);
        Route::get('journal/edit-requests/pending', [JournalLessonController::class, 'pendingEditRequests'])->middleware('permission:journal.reopen');
        Route::get('journal/edit-requests/history', [JournalLessonController::class, 'editRequestHistory'])->middleware('permission:journal.reopen');
        Route::post('journal/from-schedule/{scheduleEntry}/open', [JournalLessonController::class, 'openFromSchedule'])->middleware('permission:journal.edit');
        Route::post('journal/from-legacy-schedule/{scheduleLesson}/open', [JournalLessonController::class, 'openFromLegacySchedule'])->middleware('permission:journal.edit');
        Route::put('journal/lessons/{lesson}', [JournalLessonController::class, 'update'])->middleware('permission:journal.edit');
        Route::post('journal/lessons/{lesson}/complete', [JournalLessonController::class, 'complete'])->middleware('permission:journal.complete');
        Route::post('journal/lessons/{lesson}/sign', [JournalLessonController::class, 'sign'])->middleware('permission:journal.sign');
        Route::post('journal/lessons/{lesson}/reopen', [JournalLessonController::class, 'reopen'])->middleware('permission:journal.reopen');
        Route::post('journal/lessons/{lesson}/edit-requests', [JournalLessonController::class, 'requestEdit'])->middleware('permission:journal.edit');
        Route::post('journal/edit-requests/{journalEditRequest}/review', [JournalLessonController::class, 'reviewEditRequest'])->middleware('permission:journal.reopen');
        Route::put('journal/lessons/{lesson}/attendance', [JournalLessonController::class, 'attendance'])->middleware('permission:journal.attendance');
        Route::put('journal/lessons/{lesson}/grades', [JournalLessonController::class, 'grades'])->middleware('permission:journal.grades');
        Route::get('journal/lessons/{lesson}/attendance-suggestion', [JournalLessonController::class, 'attendanceSuggestion']);
        Route::post('journal/lessons/{lesson}/attendance-suggestion/apply', [JournalLessonController::class, 'applyAttendanceSuggestion'])->middleware('permission:journal.attendance');
        Route::post('journal/lessons/{lesson}/files', [JournalLessonController::class, 'storeFile'])->middleware('permission:journal.files');
        Route::get('journal/lessons/{lesson}/files/{file}/download', [JournalLessonController::class, 'downloadFile']);
        Route::delete('journal/lessons/{lesson}/files/{file}', [JournalLessonController::class, 'destroyFile'])->middleware('permission:journal.files');
        Route::get('journal/lessons/{lesson}/export.csv', [JournalLessonController::class, 'exportLesson'])->middleware('permission:journal.export');
    });

    // Отметки и оценки студента списком — для его карточки и кабинета. Читают
    // журнал; своей записи у них больше нет, и это главное здесь: оценку
    // ставят в журнале, где у неё есть занятие, автор и подпись. Пока путей
    // записи было два, половина оценок оказывалась вне журнала, а студент не
    // видел ни одной (16.08.2026).
    Route::get('attendance', [AttendanceController::class, 'index'])
        ->middleware('permission:journal.view');
    Route::get('grades', [GradeController::class, 'index'])
        ->middleware('permission:journal.view');

    Route::get('reports/attendance-by-group', [ReportController::class, 'attendanceByGroup'])
        ->middleware('permission:journal.view');
    Route::get('reports/attendance-by-group/export', [ReportController::class, 'exportAttendanceByGroup'])
        ->middleware('permission:journal.export');
    Route::get('reports/grades-by-group', [ReportController::class, 'gradesByGroup'])
        ->middleware('permission:journal.view');
    Route::get('reports/grades-by-group/export', [ReportController::class, 'exportGradesByGroup'])
        ->middleware('permission:journal.export');
});
