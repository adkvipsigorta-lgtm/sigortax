/**
 * Filter toolbar dropdown compact standardı.
 * Liste/filter context'indeki USelect/USelectMenu için :ui prop değeri döner.
 * Modal dropdown'lara uygulanmaz — sadece filter context'te kullanılır.
 *
 * Width: .dropdown-trigger-match CSS class (global, tüm dropdown'lar için)
 * Filter-specific: .filter-dropdown-panel → max-height: 300px
 */
export const filterDropdownUi = {
  content: 'dropdown-trigger-match filter-dropdown-panel bg-default shadow-lg rounded-lg ring ring-default overflow-hidden pointer-events-auto flex flex-col data-[state=open]:animate-[scale-in_100ms_ease-out] data-[state=closed]:animate-[scale-out_100ms_ease-in] origin-(--reka-select-content-transform-origin) origin-(--reka-combobox-content-transform-origin)',
  item: 'group relative w-full flex items-center select-none outline-none before:absolute before:z-[-1] before:inset-px before:rounded-md data-disabled:cursor-not-allowed data-disabled:opacity-75 text-default data-highlighted:not-data-disabled:text-highlighted data-highlighted:not-data-disabled:before:bg-elevated/50 p-1.5 text-sm gap-1.5 transition-colors before:transition-colors'
}
