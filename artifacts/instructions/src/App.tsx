import { Switch, Route, Router as WouterRouter } from "wouter";
import InstructionsPage from "@/pages/instructions";
import TestChat from "@/pages/test-chat";
import NotFound from "@/pages/not-found";

function Router() {
  return (
    <Switch>
      <Route path="/" component={InstructionsPage} />
      <Route path="/test" component={TestChat} />
      <Route component={NotFound} />
    </Switch>
  );
}

function App() {
  return (
    <WouterRouter base={import.meta.env.BASE_URL.replace(/\/$/, "")}>
      <Router />
    </WouterRouter>
  );
}

export default App;
