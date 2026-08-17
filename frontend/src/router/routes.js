import AuthLayout from '../layouts/AuthLayout.vue'
import AppLayout from '../layouts/AppLayout.vue'
import PublicLayout from '../layouts/PublicLayout.vue'
import MobileStudentLayout from '../layouts/MobileStudentLayout.vue'
import MobileTeacherLayout from '../layouts/MobileTeacherLayout.vue'
import MobileCuratorLayout from '../layouts/MobileCuratorLayout.vue'
import MobileAdminLayout from '../layouts/MobileAdminLayout.vue'
const LoginPage = () => import('../pages/auth/LoginPage.vue')
const DashboardPage = () => import('../pages/dashboard/DashboardPage.vue')
const MyAccountPage = () => import('../pages/account/MyAccountPage.vue')
const PeoplePage = () => import('../pages/people/PeoplePage.vue')
const StudentsPage = () => import('../pages/students/StudentsPage.vue')
const GroupsPage = () => import('../pages/groups/GroupsPage.vue')
const TeachersPage = () => import('../pages/teachers/TeachersPage.vue')
const SubjectsPage = () => import('../pages/subjects/SubjectsPage.vue')
const ClassroomsPage = () => import('../pages/classrooms/ClassroomsPage.vue')
const SpecialtiesPage = () => import('../pages/specialties/SpecialtiesPage.vue')
const EducationProgramsPage = () => import('../pages/education-programs/EducationProgramsPage.vue')
const CurriculaPage = () => import('../pages/curricula/CurriculaPage.vue')
const SchedulePage = () => import('../pages/schedule/SchedulePage.vue')
const JournalPage = () => import('../pages/journal/JournalPage.vue')
const TeachingLoadPage = () => import('../pages/teaching-load/TeachingLoadPage.vue')
const ExamsPage = () => import('../pages/exams/ExamsPage.vue')
const GraduationPage = () => import('../pages/graduation/GraduationPage.vue')
const FrdoPage = () => import('../pages/frdo/FrdoPage.vue')
const FisPage = () => import('../pages/fis/FisPage.vue')
const ReportsPage = () => import('../pages/reports/ReportsPage.vue')
const CuratorGroupPage = () => import('../pages/curator/CuratorGroupPage.vue')
const AttendancePage = () => import('../pages/attendance/AttendancePage.vue')
const AdmissionsPage = () => import('../pages/admissions/AdmissionsPage.vue')
const AdmissionsFoundationPage = () => import('../pages/admissions/FoundationWorkspacePage.vue')
const DigitalPassesPage = () => import('../pages/identity/DigitalPassesPage.vue')
const MyDigitalPassPage = () => import('../pages/identity/MyDigitalPassPage.vue')
const AccessGatePage = () => import('../pages/access/AccessGatePage.vue')
const MobileScannerPage = () => import('../pages/access/MobileScannerPage.vue')
const AccessReportsPage = () => import('../pages/access/AccessReportsPage.vue')
const MusterPage = () => import('../pages/access/MusterPage.vue')
const AccessPointsPage = () => import('../pages/access/AccessPointsPage.vue')
const ScannerTestPage = () => import('../pages/access/ScannerTestPage.vue')
const ApplicantPublicPage = () => import('../pages/public/ApplicantPublicPage.vue')
const UiFoundationPage = () => import('../pages/system/UiFoundationPage.vue')
const ForbiddenPage = () => import('../pages/system/ForbiddenPage.vue')
const DataManagementPage = () => import('../pages/admin/DataManagementPage.vue')
const TrashPage = () => import('../pages/admin/TrashPage.vue')
const UniversalImportPage = () => import('../pages/admin/UniversalImportPage.vue')
const UsersPage = () => import('../pages/admin/users/UsersPage.vue')
const RolesPage = () => import('../pages/admin/roles/RolesPage.vue')
const PermissionsPage = () => import('../pages/admin/permissions/PermissionsPage.vue')
const AuditPage = () => import('../pages/admin/audit/AuditPage.vue')
const SettingsPage = () => import('../pages/admin/settings/SettingsPage.vue')
const ReferenceDataPage = () => import('../pages/admin/reference/ReferenceDataPage.vue')
const UatPage = () => import('../pages/admin/uat/UatPage.vue')
const HrEmployeesPage = () => import('../pages/hr/HrEmployeesPage.vue')
const HrCalendarPage = () => import('../pages/hr/HrCalendarPage.vue')
const MobileStudentHomePage = () => import('../pages/mobile/student/MobileStudentHomePage.vue')
const MobileStudentPassPage = () => import('../pages/mobile/student/MobileStudentPassPage.vue')
const MobileTeacherHomePage = () => import('../pages/mobile/teacher/MobileTeacherHomePage.vue')
const MobileTeacherPassPage = () => import('../pages/mobile/teacher/MobileTeacherPassPage.vue')
const MobileTeacherLessonPage = () => import('../pages/mobile/teacher/MobileTeacherLessonPage.vue')
const MobileCuratorHomePage = () => import('../pages/mobile/curator/MobileCuratorHomePage.vue')
const MobileCuratorGroupPage = () => import('../pages/mobile/curator/MobileCuratorGroupPage.vue')
const MobileCuratorPerformancePage = () => import('../pages/mobile/curator/MobileCuratorPerformancePage.vue')
const MobileCuratorLessonsPage = () => import('../pages/mobile/curator/MobileCuratorLessonsPage.vue')
const MobileAdminHomePage = () => import('../pages/mobile/admin/MobileAdminHomePage.vue')
const StudentCabinetPage = () => import('../pages/student/StudentCabinetPage.vue')

export const routes = [
  {
    path: '/login',
    component: AuthLayout,
    meta: { public: true },
    children: [
      {
        path: '',
        name: 'login',
        component: LoginPage,
        meta: { title: 'Вход' },
      },
    ],
  },
  {
    path: '/public',
    component: PublicLayout,
    meta: { public: true },
    children: [
      {
        path: 'applicant',
        name: 'public-applicant',
        component: ApplicantPublicPage,
        meta: { title: 'Абитуриенту' },
      },
    ],
  },

  {
    path: '/m/student',
    component: MobileStudentLayout,
    meta: { permission: 'mobile.student.view' },
    children: [
      {
        path: '',
        name: 'mobile-student-home',
        component: MobileStudentHomePage,
        meta: { title: 'Кабинет студента' },
      },
      {
        path: 'pass',
        name: 'mobile-student-pass',
        component: MobileStudentPassPage,
        meta: { title: 'QR-пропуск студента', permission: 'mobile.student.pass' },
      },
    ],
  },

  {
    path: '/m/teacher',
    component: MobileTeacherLayout,
    meta: { permission: 'mobile.teacher.view' },
    children: [
      {
        path: '',
        name: 'mobile-teacher-home',
        component: MobileTeacherHomePage,
        meta: { title: 'Кабинет преподавателя' },
      },
      {
        path: 'pass',
        name: 'mobile-teacher-pass',
        component: MobileTeacherPassPage,
        meta: { title: 'QR-пропуск преподавателя' },
      },
      {
        path: 'journal/:lessonId',
        name: 'mobile-teacher-journal',
        component: MobileTeacherLessonPage,
        meta: { title: 'Журнал занятия' },
      },
    ],
  },

  {
    path: '/m/curator',
    component: MobileCuratorLayout,
    meta: { permission: 'mobile.curator.view' },
    children: [
      {
        path: '',
        name: 'mobile-curator-home',
        component: MobileCuratorHomePage,
        meta: { title: 'Кабинет куратора' },
      },
      {
        path: 'groups/:groupId',
        name: 'mobile-curator-group',
        component: MobileCuratorGroupPage,
        meta: { title: 'Моя группа' },
      },
      {
        path: 'groups/:groupId/performance',
        name: 'mobile-curator-performance',
        component: MobileCuratorPerformancePage,
        meta: { title: 'Успеваемость' },
      },
      {
        path: 'groups/:groupId/lessons',
        name: 'mobile-curator-lessons',
        component: MobileCuratorLessonsPage,
        meta: { title: 'Занятия группы' },
      },
    ],
  },

  {
    path: '/m/admin',
    component: MobileAdminLayout,
    meta: { permission: 'mobile.admin.view' },
    children: [
      {
        path: '',
        name: 'mobile-admin-home',
        component: MobileAdminHomePage,
        meta: { title: 'Кабинет администратора' },
      },
    ],
  },
  {
    path: '/forbidden',
    name: 'forbidden',
    component: ForbiddenPage,
    meta: { title: 'Недостаточно прав' },
  },
  {
    path: '/',
    component: AppLayout,
    children: [
      {
        path: '',
        redirect: '/dashboard',
      },
      {
        path: 'dashboard',
        name: 'dashboard',
        component: DashboardPage,
        meta: { title: 'Панель' },
      },
      {
        // Без права намеренно: свою учётную запись открывает любой вошедший.
        path: 'account',
        name: 'account',
        component: MyAccountPage,
        meta: { title: 'Моя учётная запись' },
      },
      {
        path: 'people',
        name: 'people',
        component: PeoplePage,
        meta: { title: 'Люди', permission: 'people.view' },
      },
      {
        path: 'students',
        name: 'students',
        component: StudentsPage,
        meta: { title: 'Студенты', permission: 'students.view' },
      },
      {
        path: 'student',
        name: 'student-cabinet',
        component: StudentCabinetPage,
        meta: { title: 'Успеваемость', roles: ['student'], permission: 'mobile.student.view' },
      },
      {
        path: 'groups',
        name: 'groups',
        component: GroupsPage,
        meta: { title: 'Группы', permission: 'groups.view' },
      },
      {
        path: 'teachers',
        name: 'teachers',
        component: TeachersPage,
        meta: { title: 'Преподаватели', permission: 'teachers.view' },
      },
      {
        path: 'subjects',
        name: 'subjects',
        component: SubjectsPage,
        meta: { title: 'Дисциплины', permission: 'subjects.view' },
      },
      {
        path: 'curricula',
        name: 'curricula',
        component: CurriculaPage,
        meta: { title: 'Учебные планы', permission: 'curricula.view' },
      },
      {
        path: 'classrooms',
        name: 'classrooms',
        component: ClassroomsPage,
        meta: { title: 'Аудитории', permission: 'classrooms.view' },
      },
      {
        // Раздел открыт и тем, кто их правит, и тем, кому они нужны по работе:
        // учебным частям — срок обучения, форма, квалификация для диплома.
        // Было закрыто только `reference.manage`, и получалось «данные читаются,
        // а раздела нет»: сервер отдаёт списки по `reference.view`, выданному
        // всем, а меню и маршрутизатор держали правку. Создание и правка на
        // экране всё равно закрыты отдельной проверкой на сервере — читатель
        // получит раздел только на чтение.
        path: 'specialties',
        name: 'specialties',
        component: SpecialtiesPage,
        meta: { title: 'Специальности', permissionsAny: ['reference.manage', 'reference.programs.view'] },
      },
      {
        path: 'education-programs',
        name: 'education-programs',
        component: EducationProgramsPage,
        meta: { title: 'Образовательные программы', permissionsAny: ['reference.manage', 'reference.programs.view'] },
      },
      {
        path: 'schedule',
        name: 'schedule',
        component: SchedulePage,
        meta: { title: 'Расписание', permission: 'schedule.view' },
      },
      {
        path: 'journal',
        name: 'journal',
        component: JournalPage,
        meta: { title: 'Журнал', permission: 'journal.view' },
      },
      {
        // Раздел куратора закрыт тем же правом, что и журнал: ничего сверх
        // журнала он не показывает, а чью группу видно — решает сервер.
        path: 'curator/group',
        name: 'curator-group',
        component: CuratorGroupPage,
        meta: { title: 'Моя группа', permission: 'journal.view' },
      },
      {
        path: 'teaching-load',
        name: 'teaching-load',
        component: TeachingLoadPage,
        meta: { title: 'Нагрузка преподавателей', permissionsAny: ['teachingload.view', 'view_own_data'] },
      },
      {
        path: 'exams',
        name: 'exams',
        component: ExamsPage,
        meta: { title: 'Экзамены и ГИА', permission: 'exams.view' },
      },
      {
        path: 'graduation',
        name: 'graduation',
        component: GraduationPage,
        meta: { title: 'Выпускники и дипломы', permission: 'graduation.view' },
      },
      {
        path: 'frdo',
        name: 'frdo',
        component: FrdoPage,
        meta: { title: 'ФРДО', permission: 'frdo.view' },
      },
      {
        path: 'fis',
        name: 'fis',
        component: FisPage,
        meta: { title: 'ФИС', permission: 'fis.view' },
      },
      {
        path: 'reports',
        name: 'reports',
        component: ReportsPage,
        meta: { title: 'Отчеты', permission: 'journal.view' },
      },
      {
        path: 'attendance',
        name: 'attendance',
        component: AttendancePage,
        meta: { title: 'Посещаемость', permission: 'attendance.reports' },
      },
      {
        path: 'admissions',
        name: 'admissions',
        component: AdmissionsPage,
        meta: { title: 'Приемная комиссия', permission: 'admissions.view' },
      },
      {
        path: 'admissions/foundation',
        name: 'admissions-foundation',
        component: AdmissionsFoundationPage,
        meta: { title: 'Приёмная комиссия', permission: 'admissions.application.view' },
      },
      {
        path: 'hr/employees',
        name: 'hr-employees',
        component: HrEmployeesPage,
        meta: { title: 'Сотрудники', permission: 'hr.employees.view' },
      },
      {
        path: 'hr/calendar',
        name: 'hr-calendar',
        component: HrCalendarPage,
        meta: { title: 'Кадровый календарь', permission: 'hr.calendar.view' },
      },
      {
        path: 'hr/departments',
        name: 'hr-departments',
        component: HrEmployeesPage,
        meta: { title: 'Подразделения', permission: 'hr.departments.manage' },
      },
      {
        path: 'hr/positions',
        name: 'hr-positions',
        component: HrEmployeesPage,
        meta: { title: 'Должности', permission: 'hr.positions.manage' },
      },
      {
        path: 'access/gate',
        name: 'access-gate',
        component: AccessGatePage,
        meta: { title: 'Проходная', roles: ['admin', 'security'], permission: 'gate.scan' },
      },
      {
        path: 'access/mobile-scanner',
        name: 'access-mobile-scanner',
        component: MobileScannerPage,
        meta: { title: 'Мобильный сканер', roles: ['admin', 'security'], permission: 'gate.scan' },
      },
      {
        path: 'access/reports',
        name: 'access-reports',
        component: AccessReportsPage,
        meta: { title: 'Отчеты по проходам', roles: ['admin', 'security'], permission: 'gate.reports' },
      },
      {
        path: 'access/muster',
        name: 'access-muster',
        component: MusterPage,
        meta: { title: 'Кто сейчас в здании', roles: ['admin', 'security', 'hr'], permission: 'gate.reports' },
      },
      {
        path: 'access/points',
        name: 'access-points',
        component: AccessPointsPage,
        meta: { title: 'Корпуса и точки прохода', roles: ['admin', 'security'], permission: 'gate.points.manage' },
      },
      {
        path: 'access/scanner-test',
        name: 'access-scanner-test',
        component: ScannerTestPage,
        meta: { title: 'Тест QR-сканера', adminOnly: true, permission: 'gate.scan' },
      },
      {
        path: 'identity/my-pass',
        name: 'identity-my-pass',
        component: MyDigitalPassPage,
        meta: { title: 'Мой QR-пропуск', roles: ['student', 'teacher', 'employee', 'hr'], permissionsAny: ['mobile.student.pass', 'view_own_data'] },
      },
      {
        path: 'identity/digital-passes',
        name: 'identity-digital-passes',
        component: DigitalPassesPage,
        meta: { title: 'Цифровые пропуска', roles: ['admin', 'security'], permission: 'digitalpasses.manage' },
      },
      {
        path: 'admin/audit',
        name: 'admin-audit',
        component: AuditPage,
        meta: { title: 'Аудит', permission: 'audit.view' },
      },
      {
        path: 'admin/settings',
        name: 'admin-settings',
        component: SettingsPage,
        meta: { title: 'Настройки колледжа', permission: 'settings.manage' },
      },
      {
        path: 'admin/reference',
        name: 'admin-reference',
        component: ReferenceDataPage,
        meta: { title: 'Справочники', permission: 'reference.manage' },
      },
      {
        path: 'admin/roles',
        name: 'admin-roles',
        component: RolesPage,
        meta: { title: 'Роли', permission: 'roles.manage' },
      },
      {
        path: 'admin/permissions',
        name: 'admin-permissions',
        component: PermissionsPage,
        meta: { title: 'Разрешения', permission: 'permissions.manage' },
      },
      {
        path: 'admin/users',
        name: 'admin-users',
        component: UsersPage,
        meta: { title: 'Пользователи', permission: 'users.manage' },
      },
      {
        path: 'admin/import',
        name: 'admin-import',
        component: UniversalImportPage,
        meta: { title: 'Импорт данных', permission: 'import.manage' },
      },
      {
        path: 'admin/data-management',
        name: 'admin-data-management',
        component: DataManagementPage,
        meta: { title: 'Управление данными', permissionsAny: ['demo_data.manage', 'settings.manage'] },
      },
      {
        path: 'admin/trash',
        name: 'admin-trash',
        component: TrashPage,
        meta: { title: 'Корзина', permission: 'trash.manage' },
      },
      {
        path: 'admin/uat',
        name: 'admin-uat',
        component: UatPage,
        meta: { title: 'UAT', permission: 'uat.manage' },
      },
      {
        path: 'system/ui-foundation',
        name: 'system-ui-foundation',
        component: UiFoundationPage,
        meta: { title: 'Библиотека интерфейса', adminOnly: true },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard',
  },
]
