export default defineAppConfig({
  ui: {
    colors: {
      primary: 'blue',
      neutral: 'slate'
    },
    button: {
      variants: {
        size: {
          xl: {
            base: 'px-4 text-base gap-2 min-h-[50px]'
          }
        }
      }
    },
    table: {
      thead: 'bg-gray-50 dark:bg-gray-800/50'
    }
  }
})
