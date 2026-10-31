import type { NextConfig } from "next";

const api = process.env.NEXT_PUBLIC_API_URL
const web = process.env.NEXT_PUBLIC_WEB_URL
const paired = (api === "http://nosql.localhost:8010" && web === "http://nosql.localhost:3310") || (api === "http://localhost:8020" && web === "http://localhost:3320")
if (!paired || process.env.API_URL !== api) throw new Error("NOSQL_WEB_CONFIG_REJECTED")

const nextConfig: NextConfig = {
  images: {
    remotePatterns: [
      {
        protocol: "https",
        hostname: "images.unsplash.com",
      },
    ],
  },
};

export default nextConfig;
