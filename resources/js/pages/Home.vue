<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { login, logout, dashboard } from '@/routes'
</script>

<template>
  <Head title="Welcome" />

  <!-- Background with subtle gradient; centers content nicely on all screens -->
  <div class="min-h-screen w-full bg-white dark:bg-[#0a0a0a] bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-white to-[#f6f6f4] dark:from-[#0a0a0a] dark:to-[#111] flex items-center justify-center px-6">
    <div class="w-full max-w-md rounded-2xl border border-[#e5e5e0]/70 dark:border-[#3E3E3A] bg-white/90 dark:bg-[#161615]/90 shadow-[0_10px_30px_-10px_rgba(0,0,0,0.25)] backdrop-blur-sm p-8">
      <div class="text-center">
        <h1 class="text-2xl md:text-3xl font-semibold tracking-tight text-[#1b1b18] dark:text-[#EDEDEC]">Welcome back</h1>
        <p class="mt-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">Sign in to continue to your dashboard</p>
      </div>

      <div class="mt-8 flex flex-col items-center gap-3" v-if="$page.props.auth?.user">
        <Link :href="dashboard()" class="w-full inline-flex justify-center items-center rounded-md border border-transparent bg-[#f53003] dark:bg-[#FF4433] px-5 py-3 text-sm font-medium text-white hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#f53003]/60 dark:focus:ring-[#FF4433]/60">
          Go to dashboard
        </Link>
        <Link :href="logout().url()" method="post" as="button" class="w-full inline-flex justify-center items-center rounded-md border border-[#19140035] dark:border-[#3E3E3A] px-5 py-3 text-sm font-medium text-[#1b1b18] hover:border-[#1915014a] dark:text-[#EDEDEC] dark:hover:border-[#62605b]">
          Log out
        </Link>
      </div>

      <div class="mt-8" v-else>
        <Link :href="login()" class="w-full inline-flex justify-center items-center rounded-md border border-transparent bg-[#1b1b18] text-white px-5 py-3 text-sm font-medium hover:opacity-95 dark:bg-[#EDEDEC] dark:text-[#0a0a0a] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#1b1b18]/50 dark:focus:ring-[#EDEDEC]/40">
          Log in
        </Link>
        <p class="mt-4 text-center text-xs text-[#706f6c] dark:text-[#A1A09A]">Have an account already? Great — just tap Log in.</p>
      </div>
    </div>
  </div>
</template>
