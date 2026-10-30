"use client"
import { useEffect,useState } from "react"
import { useCart } from "./cart-context"
import { shopRequest } from "@/lib/shop-api"
import { ShopStatus,Message } from "./shop-shared"
export function PolicyPage({slug}:{slug:string}){const{chainId}=useCart(),[page,setPage]=useState<{title:string;body:string}|null>(null),[error,setError]=useState("");useEffect(()=>{if(chainId)void shopRequest<typeof page>(`/api/shop/${chainId}/pages/${slug}`).then(setPage).catch(e=>setError(e.message))},[chainId,slug]);return <main className="page-shell ng-page"><p className="ng-eyebrow">CHÍNH SÁCH AGROVIA</p><ShopStatus/><Message error>{error}</Message>{page&&<article className="ng-policy"><h1>{page.title}</h1><div>{page.body}</div></article>}</main>}
