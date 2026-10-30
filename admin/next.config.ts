import type { NextConfig } from "next";

if (process.env.NEXT_PUBLIC_API_URL !== "http://nosql.localhost:8010" || process.env.API_URL !== "http://nosql.localhost:8010" || process.env.NEXT_PUBLIC_WEB_URL !== "http://nosql.localhost:3310") {
  throw new Error("NOSQL_WEB_CONFIG_REJECTED")
}

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
