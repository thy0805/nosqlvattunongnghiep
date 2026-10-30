import type { ComponentProps, ReactNode } from "react"
import { ArrowLeftRight, BarChart3, Boxes, Headset, LayoutDashboard, Settings2, ShoppingBag, Sparkles, Users, type LucideIcon } from "lucide-react"

const icons: Record<string, LucideIcon> = { dashboard: LayoutDashboard, bag: ShoppingBag, box: Boxes, chart: BarChart3, users: Users, chat: Headset, settings: Settings2, spark: Sparkles, transfer: ArrowLeftRight }

export function AdminIcon({ name, ...props }: { name: string } & ComponentProps<LucideIcon>) {
  const Icon = icons[name] ?? Boxes
  return <Icon aria-hidden="true" size={20} strokeWidth={1.7} {...props} />
}

export function AdminCard({ title, description, children, className = "" }: { title: string; description?: string; children: ReactNode; className?: string }) {
  return <section className={`av2-card ${className}`}><div className="av2-card-heading"><h2>{title}</h2>{description && <p>{description}</p>}</div><div className="av2-card-body">{children}</div></section>
}

export function AdminTable({ caption, headings, children }: { caption: string; headings: string[]; children: ReactNode }) {
  return <div className="av2-table-scroll" role="region" aria-label={caption} tabIndex={0}><table className="av2-table"><caption className="av2-sr-only">{caption}</caption><thead><tr>{headings.map(heading => <th key={heading} scope="col">{heading}</th>)}</tr></thead><tbody>{children}</tbody></table></div>
}

export function AdminField({ label, children }: { label: string; children: ReactNode }) {
  return <label className="av2-field"><span>{label}</span>{children}</label>
}

export function AdminFormShell({ children, ...props }: ComponentProps<"fieldset">) {
  return <fieldset className="av2-form-shell" {...props}>{children}</fieldset>
}
