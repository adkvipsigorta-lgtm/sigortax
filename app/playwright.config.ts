import { defineConfig } from 'playwright/test'
import path from 'path'
import fs from 'fs'

const authFile = path.join(__dirname, 'e2e', '.auth', 'user.json')
const hasAuth = fs.existsSync(authFile)

export default defineConfig({
  testDir: './e2e',
  timeout: 30000,
  retries: 0,
  use: {
    baseURL: 'http://localhost:3000',
    headless: true,
    viewport: { width: 1440, height: 900 },
    actionTimeout: 10000,
    navigationTimeout: 15000,
  },
  projects: [
    // Auth setup — sadece --headed modda, interaktif login
    {
      name: 'auth-setup',
      testMatch: 'auth-setup.ts',
      use: { headless: false },
    },
    // State/unit testler — auth gerektirmez
    {
      name: 'state-contract',
      testMatch: [
        'task-form-modal.spec.ts',
        'login-state.spec.ts',
        'customer-detail-tabs.spec.ts',
        'multi-state-smoke.spec.ts',
        'display-name-formatters.spec.ts',
      ],
      use: { browserName: 'chromium' },
    },
    // Authenticated E2E — storageState gerektirir
    ...(hasAuth
      ? [{
          name: 'authenticated-e2e',
          testMatch: 'real-*.spec.ts',
          use: {
            browserName: 'chromium' as const,
            storageState: authFile,
          },
        }]
      : []),
  ],
})
