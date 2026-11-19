import { createRouter, createWebHistory } from 'vue-router'
import { initAuth, useAuth } from '@/features/authentication/useAuth'
import HomePage from '@/features/home/pages/HomePage.vue'
import { site } from '@/lib/site'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    /** Header sits transparent over a full-bleed photo. */
    overlayHeader?: boolean
    requiresAuth?: boolean
    guestOnly?: boolean
  }
}

const router = createRouter({
  // Assets load from /app/ in production; page URLs stay at the site root.
  history: createWebHistory('/'),
  routes: [
    { path: '/', name: 'home', component: HomePage, meta: { overlayHeader: true } },
    {
      path: '/explore',
      name: 'explore',
      component: () => import('@/features/explore/pages/ExplorePage.vue'),
      meta: { title: 'Explore Nepal' },
    },
    {
      path: '/explore/:slug',
      name: 'destination',
      component: () => import('@/features/explore/pages/DestinationPage.vue'),
      props: true,
    },
    { path: '/routes', name: 'routes', component: () => import('@/features/routes/pages/RoutesPage.vue'), meta: { title: 'Routes' } },
    { path: '/routes/:slug', name: 'route', component: () => import('@/features/routes/pages/RoutePage.vue'), props: true },
    { path: '/experiences', name: 'experiences', component: () => import('@/features/experiences/pages/ExperiencesPage.vue'), meta: { title: 'Rider experiences' } },
    { path: '/experiences/:id/edit', name: 'experience-edit', component: () => import('@/features/experiences/pages/ContributePage.vue'), props: true, meta: { title: 'Edit your contribution', requiresAuth: true } },
    { path: '/experiences/:id', name: 'experience', component: () => import('@/features/experiences/pages/ExperiencePage.vue'), props: true },
    { path: '/contribute', name: 'contribute', component: () => import('@/features/experiences/pages/ContributePage.vue'), meta: { title: 'Share what you found', requiresAuth: true } },
    { path: '/events', name: 'events', component: () => import('@/features/events/pages/EventsPage.vue'), meta: { title: 'Events and rides' } },
    { path: '/events/new', name: 'event-new', component: () => import('@/features/events/pages/EventFormPage.vue'), meta: { title: 'Host an event', requiresAuth: true } },
    { path: '/events/:id', name: 'event', component: () => import('@/features/events/pages/EventPage.vue'), props: true },
    { path: '/events/:id/edit', name: 'event-edit', component: () => import('@/features/events/pages/EventFormPage.vue'), props: true, meta: { title: 'Edit event', requiresAuth: true } },
    { path: '/events/:id/manage', name: 'event-manage', component: () => import('@/features/events/pages/EventManagePage.vue'), props: true, meta: { title: 'Manage event', requiresAuth: true } },
    { path: '/preparation', name: 'preparation', component: () => import('@/features/preparation/pages/PreparationPage.vue'), meta: { title: 'Preparation and safety' } },
    { path: '/preparation/:slug', name: 'guide', component: () => import('@/features/preparation/pages/GuidePage.vue'), props: true },
    { path: '/rides', name: 'rides', component: () => import('@/features/rides/pages/RidesPage.vue'), meta: { title: 'My rides', requiresAuth: true } },
    { path: '/rides/:id', name: 'trip', component: () => import('@/features/rides/pages/TripPage.vue'), props: true, meta: { title: 'Trip', requiresAuth: true } },
    { path: '/riders/:username', name: 'rider', component: () => import('@/features/profiles/pages/RiderPage.vue'), props: true, meta: { title: 'Rider' } },
    {
      path: '/sign-in',
      name: 'sign-in',
      component: () => import('@/features/authentication/pages/SignInPage.vue'),
      meta: { title: 'Sign in', guestOnly: true },
    },
    {
      path: '/sign-up',
      name: 'sign-up',
      component: () => import('@/features/authentication/pages/SignUpPage.vue'),
      meta: { title: 'Create an account', guestOnly: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/features/authentication/pages/ForgotPasswordPage.vue'),
      meta: { title: 'Reset your password', guestOnly: true },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/features/authentication/pages/ResetPasswordPage.vue'),
      meta: { title: 'Choose a new password' },
    },
    {
      path: '/account',
      name: 'account',
      component: () => import('@/features/profiles/pages/AccountPage.vue'),
      meta: { title: 'Your account', requiresAuth: true },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/app/pages/NotFoundPage.vue'),
      meta: { title: 'Page not found' },
    },
  ],
  scrollBehavior(to, _from, saved) {
    if (saved) return saved
    if (to.hash) return { el: to.hash }
    return { top: 0 }
  },
})

// UX only: the database refuses unauthorized reads and writes whatever the router does.
router.beforeEach(async (to) => {
  await initAuth()
  const { isSignedIn } = useAuth()
  if (to.meta.requiresAuth && !isSignedIn.value) return { name: 'sign-in', query: { next: to.fullPath } }
  if (to.meta.guestOnly && isSignedIn.value) return { name: 'account' }
})

router.afterEach((to) => {
  const name = site.value?.general.site_name ?? 'My Ride Nepal'
  document.title = to.meta.title ? `${to.meta.title} | ${name}` : (site.value?.seo.default_title ?? 'My Ride Nepal: motorcycle travel in Nepal')
})

export default router
