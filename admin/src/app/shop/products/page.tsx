import { Suspense } from "react"
import { ProductsCatalog } from "@/components/shop/products-catalog"
export default function ProductsPage() { return <main className="page-shell ng-page"><p className="ng-eyebrow">DANH MỤC AGROVIA</p><h1>Chọn vật tư cho mùa vụ.</h1><p className="ng-intro">Tìm theo nhu cầu, so sánh thông tin và chọn sản phẩm phù hợp với cây trồng.</p><Suspense fallback={<p>Đang tải danh mục…</p>}><ProductsCatalog/></Suspense></main> }
