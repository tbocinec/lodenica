/** Navigation tree types shared by AppShell and NavGroup. */

export interface NavItem {
  /** Route path, or an absolute URL when `external`. */
  to: string;
  label: string;
  icon: string;
  /** Rendered as <a target="_blank"> instead of a RouterLink. */
  external?: boolean;
  /** Small count at the right edge (e.g. requests waiting for approval). */
  badge?: number;
}

export interface NavSubgroup {
  label: string;
  items: NavItem[];
}

export interface NavGroup {
  key: string;
  label: string;
  icon: string;
  items: NavItem[];
  /** Titled clusters rendered under the group's own items. */
  subgroups?: NavSubgroup[];
}

/** Builds an external nav entry, or nothing when the URL is not configured. */
export function externalItem(url: string | null | undefined, label: string, icon: string): NavItem[] {
  return url ? [{ to: url, label, icon, external: true }] : [];
}

/** Whether `path` is "inside" `to` for active-state highlighting. */
export function isActivePath(path: string, to: string): boolean {
  if (to === '/') return path === '/';
  return path.startsWith(to);
}

/** Whether any internal item of the group (or its subgroups) is active. */
export function groupIsActive(group: NavGroup, path: string): boolean {
  const all = [...group.items, ...(group.subgroups ?? []).flatMap((s) => s.items)];
  return all.some((item) => !item.external && isActivePath(path, item.to));
}
