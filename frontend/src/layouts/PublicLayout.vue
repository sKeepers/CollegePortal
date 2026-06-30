<template>
  <q-layout view="hHh lpR fFf" class="cp-public-layout">
    <q-header bordered class="bg-white text-dark">
      <q-toolbar class="cp-public-toolbar">
        <q-toolbar-title>
          <strong>CollegePortal</strong>
          <span class="cp-toolbar-subtitle">Абитуриенту</span>
        </q-toolbar-title>
        <q-btn flat color="primary" label="Войти" to="/login" />
      </q-toolbar>
    </q-header>

    <q-page-container>
      <RouterView />
    </q-page-container>
  </q-layout>
</template>
