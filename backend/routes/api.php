<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccessGateController;
use App\Http\Controllers\Api\AccessReportController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Controllers\Api\AdminPermissionController;
use App\Http\Controllers\Api\AdminSettingController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\Admissions\AdmissionApplicationController as AdmissionsAdmissionApplicationController;
use App\Http\Controllers\Api\Admissions\AdmissionDocumentFileController as AdmissionsAdmissionDocumentFileController;
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
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\HrCalendarController;
use App\Http\Controllers\Api\MobileStudentController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\PersonPhotoController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AttendanceAnalysisController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\DigitalIdentityController;
use App\Http\Controllers\Api\DemoDataController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\DashboardLayoutController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\FrdoPackageController;
use App\Http\Controllers\Api\FisPackageController;
use App\Http\Controllers\Api\FisOutboundPackageController;
use App\Http\Controllers\Api\FisAdmissionsImportController;
use App\Http\Controllers\Api\EducationProgramController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\JournalLessonController;
use App\Http\Controllers\Api\GraduateController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\StudentBulkController;
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

Route::post('auth/login', [AuthController::class, 'login']);
Route::get('settings/public', [AdminSettingController::class, 'publicSettings']);
Route::get('public/specialties', [SpecialtyController::class, 'index']);
Route::get('public/education-programs', [EducationProgramController::class, 'index']);

Route::middleware('api.token')->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('mobile/student', [MobileStudentController::class, 'show']);
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
        Route::get('employees/{employee}', [EmployeeController::class, 'show']);
        Route::post('employees', [EmployeeController::class, 'store'])->middleware('permission:hr.employees.create');
        Route::put('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:hr.employees.update');
        Route::patch('employees/{employee}', [EmployeeController::class, 'update'])->middleware('permission:hr.employees.update');
        Route::delete('employees/{employee}', [EmployeeController::class, 'destroy'])->middleware('permission:hr.employees.dismiss');
        Route::post('employees/{employee}/assignments', [EmployeeController::class, 'storeAssignment'])->middleware('permission:hr.assignments.manage');
        Route::put('employee-assignments/{assignment}', [EmployeeController::class, 'updateAssignment'])->middleware('permission:hr.assignments.manage');
        Route::patch('employee-assignments/{assignment}', [EmployeeController::class, 'updateAssignment'])->middleware('permission:hr.assignments.manage');
        Route::delete('employee-assignments/{assignment}', [EmployeeController::class, 'destroyAssignment'])->middleware('permission:hr.assignments.manage');
        Route::post('employees/{employee}/status-periods', [EmployeeController::class, 'storeStatusPeriod'])->middleware('permission:hr.statuses.manage');
        Route::put('employee-status-periods/{period}', [EmployeeController::class, 'updateStatusPeriod'])->middleware('permission:hr.statuses.manage');
        Route::patch('employee-status-periods/{period}', [EmployeeController::class, 'updateStatusPeriod'])->middleware('permission:hr.statuses.manage');
        Route::delete('employee-status-periods/{period}', [EmployeeController::class, 'destroyStatusPeriod'])->middleware('permission:hr.statuses.manage');
    });
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
        Route::get('people/{person}', [PersonController::class, 'show']);
        Route::get('people/{person}/profiles', [PersonController::class, 'profiles']);
    });

    Route::middleware('permission:view_reports')->group(function (): void {
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
    Route::patch('admissions/applicants/{applicant}/snils', [AdmissionsApplicantSnilsController::class, 'update'])
        ->whereNumber('applicant')
        ->middleware('permission:admissions.document.update');

    Route::get('admissions/applications', [AdmissionsAdmissionApplicationController::class, 'index'])
        ->middleware('permission:admissions.application.view');
    Route::get('admissions/applications/{application}', [AdmissionsAdmissionApplicationController::class, 'show'])
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

    Route::middleware('permission:manage_users')->group(function (): void {
        Route::get('admin/audit', [AuditLogController::class, 'index']);
        Route::get('admin/settings', [AdminSettingController::class, 'index']);
        Route::put('admin/settings', [AdminSettingController::class, 'update']);
        Route::get('admin/audit/{auditLog}', [AuditLogController::class, 'show']);
        Route::apiResource('admin/permissions', AdminPermissionController::class)->except(['show']);
        Route::post('admin/permissions/{permission}/roles', [AdminPermissionController::class, 'assignRoles']);
        Route::get('admin/permissions/roles/list', [AdminPermissionController::class, 'roles']);
        Route::apiResource('admin/roles', AdminRoleController::class)->except(['show']);
        Route::get('admin/users/roles', [AdminUserController::class, 'roles']);
        Route::get('admin/users/people', [AdminUserController::class, 'people']);
        Route::post('admin/users/{user}/roles', [AdminUserController::class, 'assignRoles']);
        Route::post('admin/users/{user}/block', [AdminUserController::class, 'block']);
        Route::post('admin/users/{user}/unblock', [AdminUserController::class, 'unblock']);
        Route::apiResource('admin/users', AdminUserController::class);
    });

    Route::middleware('permission:manage_dictionaries')->group(function (): void {
        Route::apiResource('admin/reference/catalogs', ReferenceCatalogController::class)->parameters(['catalogs' => 'catalog'])->except(['show']);
        Route::apiResource('admin/reference/items', ReferenceItemController::class)->parameters(['items' => 'item'])->except(['show']);
        Route::get('admin/import/config', [UniversalImportController::class, 'config']);
        Route::get('admin/import/history', [UniversalImportController::class, 'history']);
        Route::get('admin/import/templates/{dataType}.csv', [UniversalImportController::class, 'template']);
        Route::post('admin/import/fis-admissions/analyze', [FisAdmissionsImportController::class, 'analyze']);
        Route::post('admin/import/fis-admissions/dry-run', [FisAdmissionsImportController::class, 'dryRun']);
        Route::post('admin/import/fis-admissions/apply', [FisAdmissionsImportController::class, 'apply']);
        Route::get('admin/import/fis-admissions/jobs/{importJob}', [FisAdmissionsImportController::class, 'show']);
        Route::post('admin/import/preview', [UniversalImportController::class, 'preview']);
        Route::post('admin/import/{importJob}/validate', [UniversalImportController::class, 'validateJob']);
        Route::post('admin/import/{importJob}/confirm', [UniversalImportController::class, 'confirm']);
        Route::get('admin/demo-data', [DemoDataController::class, 'status']);
        Route::post('admin/demo-data/create', [DemoDataController::class, 'create']);
        Route::post('admin/demo-data/clear', [DemoDataController::class, 'clear']);
        Route::post('admin/demo-data/import', [DemoDataController::class, 'import']);
        Route::get('admin/demo-data/export', [DemoDataController::class, 'export']);
        Route::post('person-photos/{type}/{id}', [PersonPhotoController::class, 'store']);
        Route::delete('person-photos/{type}/{id}', [PersonPhotoController::class, 'destroy']);
        Route::get('classrooms/export', [ClassroomController::class, 'export']);
        Route::post('classrooms/import', [ClassroomController::class, 'import']);
        Route::apiResource('classrooms', ClassroomController::class);
        Route::get('access/events', [AccessGateController::class, 'events']);
        Route::post('access/scan', [AccessGateController::class, 'scan']);
        Route::get('access/reports/summary', [AccessReportController::class, 'summary']);
        Route::get('access/reports/events', [AccessReportController::class, 'events']);
        Route::get('digital-identities', [DigitalIdentityController::class, 'index']);
        Route::post('digital-identities/issue', [DigitalIdentityController::class, 'issue']);
        Route::post('digital-identities/{digitalIdentity}/revoke', [DigitalIdentityController::class, 'revoke']);
        Route::get('digital-identities/{digitalIdentity}/qr', [DigitalIdentityController::class, 'qr']);
        Route::get('admissions/{applicantApplication}/documents', [ApplicantDocumentController::class, 'index'])->middleware('permission:admissions.documents.view');
        Route::post('admissions/{applicantApplication}/documents/{type}/receive', [ApplicantDocumentController::class, 'receive'])->middleware('permission:admissions.documents.receive');
        Route::post('admissions/{applicantApplication}/documents/{type}/upload', [ApplicantDocumentController::class, 'upload'])->middleware('permission:admissions.documents.upload');
        Route::post('admissions/{applicantApplication}/documents/{document}/verify', [ApplicantDocumentController::class, 'verify'])->middleware('permission:admissions.documents.verify');
        Route::post('admissions/{applicantApplication}/documents/{document}/reject', [ApplicantDocumentController::class, 'reject'])->middleware('permission:admissions.documents.reject');
        Route::put('admissions/{applicantApplication}/documents/{document}', [ApplicantDocumentController::class, 'update'])->middleware('permission:admissions.documents.receive');
        Route::delete('admissions/{applicantApplication}/documents/{document}', [ApplicantDocumentController::class, 'destroy'])->middleware('permission:admissions.documents.delete');
        Route::get('admissions/{applicantApplication}/documents/{document}/files/{file}/download', [ApplicantDocumentController::class, 'download'])->middleware('permission:admissions.documents.download');
        Route::delete('admissions/{applicantApplication}/documents/{document}/files/{file}', [ApplicantDocumentController::class, 'destroyFile'])->middleware('permission:admissions.documents.delete');
        Route::get('admissions/stats', [ApplicantApplicationController::class, 'stats']);
        Route::get('applicant-applications/stats', [ApplicantApplicationController::class, 'stats']);
        Route::post('admissions/bulk/preview', [AdmissionBulkController::class, 'preview']);
        Route::post('admissions/bulk/apply', [AdmissionBulkController::class, 'apply']);
        Route::post('applicant-applications/bulk/preview', [AdmissionBulkController::class, 'preview']);
        Route::post('applicant-applications/bulk/apply', [AdmissionBulkController::class, 'apply']);
        Route::get('applicant-applications/export', [ApplicantApplicationController::class, 'export']);
        Route::post('applicant-applications/import', [ApplicantApplicationController::class, 'import']);
        Route::post('applicant-applications/{applicantApplication}/enroll', [ApplicantApplicationController::class, 'enroll']);
        Route::patch('applicant-applications/{applicantApplication}/documents/{type}', [ApplicantApplicationController::class, 'updateDocument']);
        Route::apiResource('applicant-applications', ApplicantApplicationController::class);
        Route::get('curricula/export', [CurriculumController::class, 'export']);
        Route::post('curricula/import', [CurriculumController::class, 'import']);
        Route::get('curricula/{curriculum}/subjects', [CurriculumController::class, 'subjects'])->middleware('permission:curricula.subjects.view');
        Route::get('curricula/{curriculum}/semesters', [CurriculumController::class, 'semesters'])->middleware('permission:curricula.subjects.view');
        Route::get('curricula/{curriculum}/summary', [CurriculumController::class, 'summary'])->middleware('permission:curricula.subjects.view');
        Route::post('curricula/{curriculum}/subjects', [CurriculumController::class, 'storeSubject'])->middleware('permission:curricula.subjects.create');
        Route::put('curriculum-subjects/{curriculumSubject}', [CurriculumController::class, 'updateSubject'])->middleware('permission:curricula.subjects.update');
        Route::delete('curriculum-subjects/{curriculumSubject}', [CurriculumController::class, 'destroySubject'])->middleware('permission:curricula.subjects.delete');
        Route::post('curricula/{curriculum}/items', [CurriculumController::class, 'storeItem']);
        Route::delete('curriculum-items/{curriculumItem}', [CurriculumController::class, 'destroyItem']);
        Route::apiResource('curricula', CurriculumController::class);
        Route::get('education-programs/export', [EducationProgramController::class, 'export']);
        Route::post('education-programs/import', [EducationProgramController::class, 'import']);
        Route::apiResource('education-programs', EducationProgramController::class);
        Route::get('exams/export', [ExamController::class, 'export']);
        Route::post('exams/import', [ExamController::class, 'import']);
        Route::post('exams/{exam}/results', [ExamController::class, 'storeResult']);
        Route::delete('exam-results/{examResult}', [ExamController::class, 'destroyResult']);
        Route::apiResource('exams', ExamController::class);
        Route::get('groups/export', [GroupController::class, 'export']);
        Route::post('groups/import', [GroupController::class, 'import']);
        Route::apiResource('groups', GroupController::class);
        Route::get('graduates/export', [GraduateController::class, 'export']);
        Route::post('graduates/import', [GraduateController::class, 'import']);
        Route::post('graduates/{graduate}/diploma', [GraduateController::class, 'storeDiploma']);
        Route::post('graduates/{graduate}/supplement', [GraduateController::class, 'storeSupplement']);
        Route::apiResource('graduates', GraduateController::class);
        Route::post('frdo-packages/{frdoPackage}/validate', [FrdoPackageController::class, 'validatePackage']);
        Route::post('frdo-packages/{frdoPackage}/mark-exported', [FrdoPackageController::class, 'markExported']);
        Route::post('frdo-packages/{frdoPackage}/archive', [FrdoPackageController::class, 'archive']);
        Route::get('frdo-packages/{frdoPackage}/export.csv', [FrdoPackageController::class, 'exportCsv']);
        Route::get('frdo-packages/{frdoPackage}/export.json', [FrdoPackageController::class, 'exportJson']);
        Route::apiResource('frdo-packages', FrdoPackageController::class)->only(['index', 'store', 'show']);
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
        Route::post('fis/outbound/packages/{package}/generate', [FisOutboundPackageController::class, 'generate'])->middleware('permission:fis.outbound.generate');
        Route::post('fis/outbound/packages/{package}/validate', [FisOutboundPackageController::class, 'validatePackage'])->middleware('permission:fis.outbound.validate');
        Route::post('fis/outbound/packages/{package}/send-preview', [FisOutboundPackageController::class, 'sendPreview'])->middleware('permission:fis.outbound.send_test');
        Route::post('fis/outbound/packages/{package}/send', [FisOutboundPackageController::class, 'send'])->middleware('permission:fis.outbound.send_test');
        Route::post('fis/outbound/packages/{package}/refresh-status', [FisOutboundPackageController::class, 'refreshStatus'])->middleware('permission:fis.outbound.status');
        Route::post('fis/outbound/packages/{package}/cancel', [FisOutboundPackageController::class, 'cancel'])->middleware('permission:fis.outbound.generate');
        Route::get('fis/outbound/packages/{package}/events', [FisOutboundPackageController::class, 'events'])->middleware('permission:fis.outbound.view');
        Route::get('fis/outbound/packages/{package}/download', [FisOutboundPackageController::class, 'download'])->middleware('permission:fis.outbound.download');
        Route::post('fis-packages/{fisPackage}/validate', [FisPackageController::class, 'validatePackage']);
        Route::post('fis-packages/{fisPackage}/mark-exported', [FisPackageController::class, 'markExported']);
        Route::post('fis-packages/{fisPackage}/archive', [FisPackageController::class, 'archive']);
        Route::get('fis-packages/{fisPackage}/export.csv', [FisPackageController::class, 'exportCsv']);
        Route::get('fis-packages/{fisPackage}/export.json', [FisPackageController::class, 'exportJson']);
        Route::apiResource('fis-packages', FisPackageController::class)->only(['index', 'store', 'show']);
        Route::get('specialties/export', [SpecialtyController::class, 'export']);
        Route::post('specialties/import', [SpecialtyController::class, 'import']);
        Route::apiResource('specialties', SpecialtyController::class);
        Route::post('students/bulk/preview', [StudentBulkController::class, 'preview']);
        Route::post('students/bulk/apply', [StudentBulkController::class, 'apply']);
        Route::get('students/export', [StudentController::class, 'export']);
        Route::post('students/import', [StudentController::class, 'import']);
        Route::apiResource('students', StudentController::class);
        Route::get('subjects/export', [SubjectController::class, 'export']);
        Route::post('subjects/import', [SubjectController::class, 'import']);
        Route::apiResource('subjects', SubjectController::class);
        Route::post('teaching-load/generate/preview', [TeachingLoadController::class, 'generatePreview'])->middleware('permission:teaching_load.generate');
        Route::post('teaching-load/generate/apply', [TeachingLoadController::class, 'generateApply'])->middleware('permission:teaching_load.generate');
        Route::get('teaching-load/{teachingLoad}/coverage', [TeachingLoadController::class, 'coverage'])->middleware('permission:teaching_load.view_coverage');
        Route::post('teaching-load/items/{teachingLoadItem}/assign-teacher', [TeachingLoadController::class, 'assignTeacher'])->middleware('permission:teaching_load.assign');
        Route::post('teaching-load/items/bulk-assign-teacher', [TeachingLoadController::class, 'bulkAssignTeacher'])->middleware('permission:teaching_load.bulk_assign');
        Route::get('teaching-loads/export', [TeachingLoadController::class, 'export']);
        Route::post('teaching-loads/import', [TeachingLoadController::class, 'import']);
        Route::post('teaching-loads/{teachingLoad}/items', [TeachingLoadController::class, 'storeItem']);
        Route::delete('teaching-load-items/{teachingLoadItem}', [TeachingLoadController::class, 'destroyItem']);
        Route::apiResource('teaching-loads', TeachingLoadController::class);
        Route::get('teachers/export', [TeacherController::class, 'export']);
        Route::post('teachers/import', [TeacherController::class, 'import']);
        Route::apiResource('teachers', TeacherController::class);
    });

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

    Route::apiResource('schedule-lessons', ScheduleLessonController::class)
        ->middleware('permission:manage_schedule');

    Route::middleware('permission:journal.view')->group(function (): void {
        Route::get('journal/lessons', [JournalLessonController::class, 'index']);
        Route::get('journal/export/group.csv', [JournalLessonController::class, 'exportGroup'])->middleware('permission:journal.export');
        Route::get('journal/export/teacher.csv', [JournalLessonController::class, 'exportTeacher'])->middleware('permission:journal.export');
        Route::get('journal/lessons/{lesson}', [JournalLessonController::class, 'show']);
        Route::post('journal/from-schedule/{scheduleEntry}/open', [JournalLessonController::class, 'openFromSchedule'])->middleware('permission:journal.edit');
        Route::put('journal/lessons/{lesson}', [JournalLessonController::class, 'update'])->middleware('permission:journal.edit');
        Route::post('journal/lessons/{lesson}/complete', [JournalLessonController::class, 'complete'])->middleware('permission:journal.complete');
        Route::post('journal/lessons/{lesson}/sign', [JournalLessonController::class, 'sign'])->middleware('permission:journal.sign');
        Route::post('journal/lessons/{lesson}/reopen', [JournalLessonController::class, 'reopen'])->middleware('permission:journal.reopen');
        Route::put('journal/lessons/{lesson}/attendance', [JournalLessonController::class, 'attendance'])->middleware('permission:journal.attendance');
        Route::put('journal/lessons/{lesson}/grades', [JournalLessonController::class, 'grades'])->middleware('permission:journal.grades');
        Route::get('journal/lessons/{lesson}/attendance-suggestion', [JournalLessonController::class, 'attendanceSuggestion']);
        Route::post('journal/lessons/{lesson}/attendance-suggestion/apply', [JournalLessonController::class, 'applyAttendanceSuggestion'])->middleware('permission:journal.attendance');
        Route::post('journal/lessons/{lesson}/files', [JournalLessonController::class, 'storeFile'])->middleware('permission:journal.files');
        Route::get('journal/lessons/{lesson}/files/{file}/download', [JournalLessonController::class, 'downloadFile']);
        Route::delete('journal/lessons/{lesson}/files/{file}', [JournalLessonController::class, 'destroyFile'])->middleware('permission:journal.files');
        Route::get('journal/lessons/{lesson}/export.csv', [JournalLessonController::class, 'exportLesson'])->middleware('permission:journal.export');
    });

    Route::middleware('permission:manage_journal')->group(function (): void {
        Route::apiResource('attendance', AttendanceController::class);
        Route::apiResource('grades', GradeController::class);
        Route::get('reports/attendance-by-group', [ReportController::class, 'attendanceByGroup']);
        Route::get('reports/attendance-by-group/export', [ReportController::class, 'exportAttendanceByGroup']);
        Route::get('reports/grades-by-group', [ReportController::class, 'gradesByGroup']);
        Route::get('reports/grades-by-group/export', [ReportController::class, 'exportGradesByGroup']);
    });
});
