"use client"

import Link from "next/link"
import { ShieldCheck } from "lucide-react"
import { useAdminProfile } from "@/components/admin/admin-shell"
import { AdminCard, AdminTable } from "./primitives"

const permissions = [
  ["Phạm vi dữ liệu", "Chuỗi được phân công", "Chi nhánh được phân công", "Chi nhánh được phân công"],
  ["Bán hàng nội bộ", "Trong chuỗi", "Trong chi nhánh", "Trong chi nhánh"],
  ["Lập phiếu nhập", "Cần thêm phân công chi nhánh", "Trong chi nhánh", "Trong chi nhánh"],
  ["Duyệt phiếu nhập", "Trong chuỗi", "Trong chi nhánh", "Không"],
  ["Báo cáo bán hàng nội bộ", "Trong chuỗi", "Trong chi nhánh", "Không"],
  ["Quản trị catalog chung", "Cần cờ quản trị catalog", "Không", "Không"],
  ["Quản trị Sync / Outbox", "Trong chuỗi", "Không", "Không"],
]

export function RoleMatrix() {
  const profile = useAdminProfile()
  return <div className="av2-role-workspace"><AdminCard title="Ma trận vai trò" description="Chỉ đọc · Không tạo hoặc chỉnh sửa quyền tùy ý."><AdminTable caption="Quyền theo vai trò cố định" headings={["Chức năng", "Chủ chuỗi · Owner", "Quản lý · Manager", "Nhân viên · Staff"]}>{permissions.map(([label, ...values]) => <tr key={label}><th scope="row">{label}</th>{values.map((value, index) => <td key={index}><span className={value === "Không" ? "av2-muted" : "av2-permission"}>{value}</span></td>)}</tr>)}</AdminTable></AdminCard><AdminCard title="Phạm vi của phiên hiện tại" description="Backend vẫn quyết định quyền ở từng yêu cầu."><p className="av2-role-note"><ShieldCheck size={22}/><span>Phân công phải đang hiệu lực; tài khoản bị khóa hoặc mất quyền sẽ bị từ chối. Người lập không tự duyệt phiếu nhập.</span></p><dl className="av2-profile"><dt>Tài khoản</dt><dd>{profile.user.username}</dd><dt>Chuỗi</dt><dd>{profile.chains.map(chain => chain.name).join(", ") || "Chưa được phân công"}</dd><dt>Chi nhánh</dt><dd>{profile.branches.map(branch => branch.name).join(", ") || "Chưa được phân công"}</dd></dl><Link className="av2-button" href="/admin/v2?screen=employees">Mở tài khoản & phân công</Link></AdminCard></div>
}
