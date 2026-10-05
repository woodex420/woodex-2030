import { Toaster } from "@/components/ui/toaster";
import { Toaster as Sonner } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { BrowserRouter, Routes, Route } from "react-router-dom";
import { QuoteProvider } from "@/contexts/QuoteContext";
import { CartProvider } from "@/contexts/CartContext";
import Index from "./pages/Index";
import Shop from "./pages/Shop";
import ProductDetail from "./pages/ProductDetail";
import RoomPackages from "./pages/RoomPackages";
import VirtualShowroom from "./pages/VirtualShowroom";
import Series from "./pages/Series";
import SeriesDetail from "./pages/SeriesDetail";
import Projects from "./pages/Projects";
import ProjectDetail from "./pages/ProjectDetail";
import Services from "./pages/Services";
import ServiceDetail from "./pages/ServiceDetail";
import B2B from "./pages/B2B";
import About from "./pages/About";
import Contact from "./pages/Contact";
import CustomDesign from "./pages/CustomDesign";
import Quotation from "./pages/Quotation";
import Showrooms from "./pages/Showrooms";
import Materials from "./pages/Materials";
import Warranty from "./pages/Warranty";
import Blog from "./pages/Blog";
import BlogPost from "./pages/BlogPost";
import Checkout from "./pages/Checkout";
import OrderStatus from "./pages/OrderStatus";
import NotFound from "./pages/NotFound";

const queryClient = new QueryClient();

const App = () => (
  <QueryClientProvider client={queryClient}>
    <QuoteProvider>
      <CartProvider>
      <TooltipProvider>
        <Toaster />
        <Sonner />
        <BrowserRouter>
          <Routes>
            <Route path="/" element={<Index />} />
            <Route path="/shop" element={<Shop />} />
            <Route path="/shop/:productId" element={<ProductDetail />} />
            <Route path="/room-packages" element={<RoomPackages />} />
            <Route path="/virtual-showroom" element={<VirtualShowroom />} />
            <Route path="/series" element={<Series />} />
            <Route path="/series/:seriesId" element={<SeriesDetail />} />
            <Route path="/projects" element={<Projects />} />
            <Route path="/projects/:projectId" element={<ProjectDetail />} />
            <Route path="/services" element={<Services />} />
            <Route path="/services/:slug" element={<ServiceDetail />} />
            <Route path="/b2b" element={<B2B />} />
            <Route path="/about" element={<About />} />
            <Route path="/contact" element={<Contact />} />
            <Route path="/custom-design" element={<CustomDesign />} />
            <Route path="/quotation" element={<Quotation />} />
            <Route path="/showrooms" element={<Showrooms />} />
            <Route path="/materials" element={<Materials />} />
            <Route path="/warranty" element={<Warranty />} />
            <Route path="/blog" element={<Blog />} />
            <Route path="/blog/:postId" element={<BlogPost />} />
            <Route path="/checkout" element={<Checkout />} />
            <Route path="/order-status" element={<OrderStatus />} />
            {/* ADD ALL CUSTOM ROUTES ABOVE THE CATCH-ALL "*" ROUTE */}
            <Route path="*" element={<NotFound />} />
          </Routes>
        </BrowserRouter>
      </TooltipProvider>
      </CartProvider>
    </QuoteProvider>
  </QueryClientProvider>
);

export default App;
