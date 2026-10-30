import { CartProvider } from "@/components/shop/cart-context"
import { ShopFooter } from "@/components/shop/shop-footer"
import { ShopHeader } from "@/components/shop/shop-header"
import { ShopAssistant } from "@/components/shop/shop-assistant"
import "./shop.css"
import "./agrovia-fonts.css"
import "./agrovia.css"

export const metadata = { title: "Agrovia — Vụ Xanh", description: "Vật tư, hạt giống và dụng cụ nông nghiệp. Cùng người làm nông chăm tốt từng mùa vụ." }
export default function ShopLayout({ children }: { children: React.ReactNode }) {
 return <div className="ng-shop"><CartProvider><ShopHeader/>{children}<ShopFooter/><ShopAssistant/></CartProvider></div>
}
