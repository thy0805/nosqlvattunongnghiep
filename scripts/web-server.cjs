const path=require('node:path');
const root=path.resolve(__dirname,'../web-quan-ly-ban-hang');
process.chdir(root);
const {startServer}=require(path.join(root,'node_modules/next/dist/server/lib/start-server.js'));
startServer({dir:root,isDev:false,hostname:'127.0.0.1',port:3320,allowRetry:false}).catch(()=>process.exit(1));
