export function statusBadgeClass(status: string): string {
  switch (status) {
    case 'Done':
      return 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300'
    case 'In Progress':
      return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
    case 'Blocked':
      return 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300'
    default: // Planned or any other
      return 'bg-muted text-muted-foreground'
  }
}
