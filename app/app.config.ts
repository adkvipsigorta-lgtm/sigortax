export default defineAppConfig({
  ui: {
    colors: {
      primary: 'blue',
      neutral: 'slate'
    },
    button: {
      variants: {
        size: {
          md: {
            base: 'px-3 py-2 text-sm gap-1.5 min-h-[38px]'
          },
          lg: {
            base: 'px-3 py-2 text-sm gap-2 min-h-[38px]'
          },
          xl: {
            base: 'px-4 py-2 text-sm gap-2 min-h-[38px]'
          }
        }
      }
    },
    table: {
      thead: 'bg-gray-50 dark:bg-gray-800/50'
    }
  }
})
