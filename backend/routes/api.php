<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccessGateController;
use App\Http\Controllers\Api\AccessReportController;
use App\Http\Controllers\Api\ApplicantApplicationController;
use App\Http\Controllers\Api\GroupController;
use App\Http\Controllers\Api\MobileStudentController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\ClassroomController;
use App\Http\Controllers\Api\CurriculumController;
use App\Http\Controllers\Api\DigitalIdentityController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\EducationProgramController;
use App\Http\Controllers\Api\GradeController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\ScheduleLessonController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SpecialtyController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\TeachingLoadController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login']);
Route::get('public/specialties', [SpecialtyController::class, 'index']);
Route::get('public/education-programs', [EducationProgramController::class, 'index']);

Route::middleware('api.token')->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('mobile/student', [MobileStudentController::class, 'show']);

    Route::middleware('permission:manage_dictionaries')->group(function (): void {
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
        Route::get('applicant-applications/export', [ApplicantApplicationController::class, 'export']);
        Route::post('applicant-applications/import', [ApplicantApplicationController::class, 'import']);
        Route::post('applicant-applications/{applicantApplication}/enroll', [ApplicantApplicationController::class, 'enroll']);
        Route::patch('applicant-applications/{applicantApplication}/documents/{type}', [ApplicantApplicationController::class, 'updateDocument']);
        Route::apiResource('applicant-applications', ApplicantApplicationController::class);
        Route::get('curricula/export', [CurriculumController::class, 'export']);
        Route::post('curricula/import', [CurriculumController::class, 'import']);
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
        Route::get('specialties/export', [SpecialtyController::class, 'export']);
        Route::post('specialties/import', [SpecialtyController::class, 'import']);
        Route::apiResource('specialties', SpecialtyController::class);
        Route::get('students/export', [StudentController::class, 'export']);
        Route::post('students/import', [StudentController::class, 'import']);
        Route::apiResource('students', StudentController::class);
        Route::get('subjects/export', [SubjectController::class, 'export']);
        Route::post('subjects/import', [SubjectController::class, 'import']);
        Route::apiResource('subjects', SubjectController::class);
        Route::get('teaching-loads/export', [TeachingLoadController::class, 'export']);
        Route::post('teaching-loads/import', [TeachingLoadController::class, 'import']);
        Route::post('teaching-loads/{teachingLoad}/items', [TeachingLoadController::class, 'storeItem']);
        Route::delete('teaching-load-items/{teachingLoadItem}', [TeachingLoadController::class, 'destroyItem']);
        Route::apiResource('teaching-loads', TeachingLoadController::class);
        Route::get('teachers/export', [TeacherController::class, 'export']);
        Route::post('teachers/import', [TeacherController::class, 'import']);
        Route::apiResource('teachers', TeacherController::class);
    });

    Route::apiResource('schedule-lessons', ScheduleLessonController::class)
        ->middleware('permission:manage_schedule');

    Route::middleware('permission:manage_journal')->group(function (): void {
        Route::apiResource('attendance', AttendanceController::class);
        Route::apiResource('grades', GradeController::class);
        Route::get('reports/attendance-by-group', [ReportController::class, 'attendanceByGroup']);
        Route::get('reports/attendance-by-group/export', [ReportController::class, 'exportAttendanceByGroup']);
        Route::get('reports/grades-by-group', [ReportController::class, 'gradesByGroup']);
        Route::get('reports/grades-by-group/export', [ReportController::class, 'exportGradesByGroup']);
    });
});
