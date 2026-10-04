export default defineAppConfig({
  ui: {
    colors: {
      primary: 'blue',
      neutral: 'slate'
    },
    button: {
      slots: {
        base: 'rounded-lg font-medium inline-flex items-center disabled:cursor-not-allowed aria-disabled:cursor-not-allowed disabled:opacity-75 aria-disabled:opacity-75'
      },
      defaultVariants: {
        size: 'md' as const,
        color: 'primary' as const,
        variant: 'solid' as const
      },
      variants: {
        size: {
          xs: {
            base: 'px-2 py-1 text-xs gap-1 h-[var(--height-button-xs)]'
          },
          sm: {
            base: 'px-2.5 py-1.5 text-xs gap-1.5 h-[var(--height-button-sm)]'
          },
          md: {
            base: 'px-3 py-2 text-sm gap-1.5 h-[var(--height-button)]'
          },
          lg: {
            base: 'px-3 py-2.5 text-sm gap-2 h-[var(--height-button)]'
          }
        }
      }
    },
    card: {
      slots: {
        root: 'rounded-xl overflow-hidden'
      },
      defaultVariants: {
        variant: 'outline' as const
      }
    },
    input: {
      slots: {
        base: 'w-full rounded-lg border-0 appearance-none placeholder:text-dimmed focus:outline-none disabled:cursor-not-allowed disabled:opacity-75'
      },
      defaultVariants: {
        size: 'md' as const,
        variant: 'outline' as const
      }
    },
    select: {
      slots: {
        base: 'relative group rounded-lg inline-flex items-center focus:outline-none disabled:cursor-not-allowed disabled:opacity-75',
        content: 'dropdown-trigger-match max-h-60 bg-default shadow-lg rounded-lg ring ring-default overflow-hidden data-[state=open]:animate-[scale-in_100ms_ease-out] data-[state=closed]:animate-[scale-out_100ms_ease-in] origin-(--reka-select-content-transform-origin) pointer-events-auto flex flex-col'
      },
      defaultVariants: {
        size: 'md' as const,
        variant: 'outline' as const
      }
    },
    selectMenu: {
      slots: {
        base: 'relative group rounded-lg inline-flex items-center focus:outline-none disabled:cursor-not-allowed disabled:opacity-75',
        content: 'dropdown-trigger-match max-h-60 bg-default shadow-lg rounded-lg ring ring-default overflow-hidden data-[state=open]:animate-[scale-in_100ms_ease-out] data-[state=closed]:animate-[scale-out_100ms_ease-in] origin-(--reka-select-content-transform-origin) origin-(--reka-combobox-content-transform-origin) pointer-events-auto flex flex-col'
      }
    },
    modal: {
      variants: {
        fullscreen: {
          false: {
            content: 'w-[calc(100vw-2rem)] max-w-lg rounded-xl shadow-lg ring ring-default'
          }
        }
      }
    },
    badge: {
      variants: {
        size: {
          xs: {
            base: 'text-[8px]/3 px-1 py-0.5 gap-1 rounded-md'
          },
          sm: {
            base: 'text-[10px]/3 px-1.5 py-1 gap-1 rounded-md'
          }
        }
      },
      defaultVariants: {
        size: 'sm' as const
      }
    },
    table: {
      thead: 'bg-gray-50 dark:bg-gray-800/50'
    }
  }
})
