import { PolicyPage } from "@/components/shop/policy-page"
export default async function Page({params}:{params:Promise<{slug:string}>}){const{slug}=await params;return <PolicyPage slug={slug}/>}
