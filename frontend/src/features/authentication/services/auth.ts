import { api } from '@/lib/api/client'

// Rider account flows (/api/v1/auth). Riders only: staff accounts sign in on the staff portal.
export const register = (body: { display_name: string; email: string; password: string }) => api.post('/auth/register', body)
export const resendConfirmation = async (email: string) => (await api.post<{ message: string }>('/auth/email/resend', { email })).data.message
export const requestPasswordReset = (email: string) => api.post('/auth/forgot-password', { email })
export const resetPassword = (body: { token: string; email: string; password: string }) => api.post('/auth/reset-password', body)
