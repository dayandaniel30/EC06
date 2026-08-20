import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// Le port 3000 n'est pas un detail : c'est l'une des origines autorisees dans
// skillhub_api/config/cors.php. En changer casserait les appels a l'API.
export default defineConfig({
  plugins: [react()],
  server: {
    port: 3000,
    // host: true -> ecoute sur toutes les interfaces, sans quoi le port publie
    // par Docker reste injoignable depuis la machine hote.
    host: true,
    strictPort: true
  },
  build: {
    outDir: "build"
  }
});
