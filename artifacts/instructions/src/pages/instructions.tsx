import { AlertTriangle, Shield, Key, Terminal, MessageSquare, Zap, ArrowRight, DollarSign, BookOpen, FlaskConical } from "lucide-react";
import { Link } from "wouter";

function WarningBanner() {
  return (
    <div className="rounded-lg border-2 border-red-300 bg-red-50 p-6">
      <div className="flex items-start gap-3">
        <AlertTriangle className="h-6 w-6 text-red-600 mt-0.5 shrink-0" />
        <div className="space-y-3">
          <h3 className="text-lg font-semibold text-red-800">
            Security Warning: You Are Responsible for Your Agent's Actions
          </h3>
          <p className="text-red-700 text-sm leading-relaxed">
            Any AI agent connected to this gateway will act on your Ontraport account using the API key you
            provide. <strong>You are fully responsible for all actions your agent takes</strong>, including creating,
            modifying, or deleting contacts, transactions, and other CRM data.
          </p>
          <p className="text-red-700 text-sm leading-relaxed">
            <strong>API keys inherit all permissions from the key owner's Ontraport user account.</strong> If the key
            owner has full admin access, the agent will too — including the ability to delete records, process
            refunds, void transactions, and modify billing.
          </p>
          <p className="text-red-700 text-sm leading-relaxed">
            <strong>Always scope your API keys to the minimum permissions required.</strong> Create a dedicated
            Ontraport user with restricted permissions specifically for agent use, and generate the API key from
            that account.
          </p>
        </div>
      </div>
    </div>
  );
}

function BetaBanner() {
  return (
    <div className="rounded-lg border-2 border-amber-300 bg-amber-50 p-6">
      <div className="flex items-start gap-3">
        <Zap className="h-6 w-6 text-amber-600 mt-0.5 shrink-0" />
        <div className="space-y-3">
          <h3 className="text-lg font-semibold text-amber-800">
            Beta Product — Use at Your Own Risk
          </h3>
          <p className="text-amber-700 text-sm leading-relaxed">
            This Agent Gateway is currently in <strong>beta</strong>. AI agents are inherently unpredictable
            — they may misinterpret instructions, take unexpected actions, or produce unintended results.
          </p>
          <p className="text-amber-700 text-sm leading-relaxed">
            <strong>We are not responsible for any agent behavior that damages, modifies, or deletes your data.</strong> By
            using this service, you acknowledge and accept this risk.
          </p>
        </div>
      </div>
    </div>
  );
}

function RollbackBanner() {
  return (
    <div className="rounded-lg border-2 border-blue-300 bg-blue-50 p-6">
      <div className="flex items-start gap-3">
        <DollarSign className="h-6 w-6 text-blue-600 mt-0.5 shrink-0" />
        <div className="space-y-3">
          <h3 className="text-lg font-semibold text-blue-800">
            Data Rollbacks — $50 Per Incident
          </h3>
          <p className="text-blue-700 text-sm leading-relaxed">
            If your agent causes unintended changes to your Ontraport data and you need a rollback,
            each rollback request costs <strong>$50</strong>. This covers the manual effort required to
            restore your data from backups.
          </p>
          <p className="text-blue-700 text-sm leading-relaxed">
            To avoid needing rollbacks, always test with a limited-permission API key first and start
            with read-only operations before enabling write access.
          </p>
        </div>
      </div>
    </div>
  );
}

function Section({ title, icon: Icon, children }: { title: string; icon: typeof Shield; children: React.ReactNode }) {
  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2">
        <Icon className="h-5 w-5 text-primary" />
        <h2 className="text-xl font-semibold text-foreground">{title}</h2>
      </div>
      {children}
    </div>
  );
}

function CodeBlock({ children }: { children: string }) {
  return (
    <pre className="rounded-lg bg-slate-900 text-slate-100 p-4 text-sm overflow-x-auto font-mono leading-relaxed">
      <code>{children}</code>
    </pre>
  );
}

function StepItem({ number, title, children }: { number: number; title: string; children: React.ReactNode }) {
  return (
    <div className="flex gap-4">
      <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary text-primary-foreground flex items-center justify-center text-sm font-bold">
        {number}
      </div>
      <div className="space-y-1 pt-0.5">
        <h3 className="font-medium text-foreground">{title}</h3>
        <div className="text-muted-foreground text-sm leading-relaxed">{children}</div>
      </div>
    </div>
  );
}

export default function InstructionsPage() {
  return (
    <div className="min-h-screen bg-background">
      <div className="max-w-3xl mx-auto px-6 py-12 space-y-10">
        <header className="space-y-2">
          <h1 className="text-3xl font-bold tracking-tight text-foreground">
            Ontraport Agent Gateway
          </h1>
          <p className="text-muted-foreground text-lg">
            Connect your AI applications to Ontraport through natural language. Send a message,
            and the gateway orchestrates Claude AI with your Ontraport MCP tools to get things done.
          </p>
        </header>

        <div className="space-y-4">
          <WarningBanner />
          <BetaBanner />
          <RollbackBanner />
        </div>

        <hr className="border-border" />

        <Section title="Authentication" icon={Key}>
          <p className="text-muted-foreground text-sm leading-relaxed">
            Every request requires your Ontraport API credentials passed as headers. Find these in{" "}
            <strong className="text-foreground">Ontraport Admin &gt; Integrations &gt; API Keys</strong>.
          </p>
          <div className="rounded-lg border border-border bg-muted/50 p-4 space-y-2">
            <div className="flex items-center gap-2">
              <code className="text-sm font-mono bg-background px-2 py-0.5 rounded border border-border">Api-Key</code>
              <span className="text-muted-foreground text-sm">Your Ontraport API key</span>
            </div>
            <div className="flex items-center gap-2">
              <code className="text-sm font-mono bg-background px-2 py-0.5 rounded border border-border">Api-Appid</code>
              <span className="text-muted-foreground text-sm">Your Ontraport App ID</span>
            </div>
          </div>
        </Section>

        <Section title="Quick Start" icon={Terminal}>
          <div className="space-y-6">
            <StepItem number={1} title="Get your Ontraport API credentials">
              <p>Go to <strong className="text-foreground">Ontraport Admin &gt; Integrations &gt; API Keys</strong> and
              create a dedicated API key with restricted permissions for agent use.</p>
            </StepItem>

            <StepItem number={2} title="Send a test message">
              <p>Make a POST request to the agent endpoint:</p>
            </StepItem>
          </div>

          <CodeBlock>{`curl -X POST ${window.location.origin}/api/v1/agent \\
  -H "Content-Type: application/json" \\
  -H "Api-Key: YOUR_API_KEY" \\
  -H "Api-Appid: YOUR_APP_ID" \\
  -d '{
    "message": "How many contacts do I have?"
  }'`}</CodeBlock>

          <div className="space-y-6">
            <StepItem number={3} title="Review the response">
              <p>The gateway returns the agent's response along with any actions it took:</p>
            </StepItem>
          </div>

          <CodeBlock>{`{
  "response": "You currently have 1,247 contacts in your Ontraport account.",
  "conversation_id": "abc123...",
  "actions_taken": ["contacts.list"],
  "usage": {
    "input_tokens": 350,
    "output_tokens": 42
  }
}`}</CodeBlock>
        </Section>

        <Section title="API Reference" icon={BookOpen}>
          <div className="space-y-6">
            <div>
              <h3 className="font-medium text-foreground flex items-center gap-2 mb-3">
                <span className="text-xs font-mono bg-green-100 text-green-700 px-2 py-0.5 rounded">POST</span>
                <code className="text-sm font-mono">/api/v1/agent</code>
              </h3>
              <p className="text-muted-foreground text-sm mb-3">Send a natural language message to the agent.</p>

              <h4 className="text-sm font-medium text-foreground mb-2">Request Body</h4>
              <div className="rounded-lg border border-border overflow-hidden">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="bg-muted/50 border-b border-border">
                      <th className="text-left px-4 py-2 font-medium text-foreground">Field</th>
                      <th className="text-left px-4 py-2 font-medium text-foreground">Type</th>
                      <th className="text-left px-4 py-2 font-medium text-foreground">Required</th>
                      <th className="text-left px-4 py-2 font-medium text-foreground">Description</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr className="border-b border-border">
                      <td className="px-4 py-2 font-mono text-xs">message</td>
                      <td className="px-4 py-2 text-muted-foreground">string</td>
                      <td className="px-4 py-2 text-muted-foreground">Yes</td>
                      <td className="px-4 py-2 text-muted-foreground">Your natural language request</td>
                    </tr>
                    <tr className="border-b border-border">
                      <td className="px-4 py-2 font-mono text-xs">conversation_id</td>
                      <td className="px-4 py-2 text-muted-foreground">string</td>
                      <td className="px-4 py-2 text-muted-foreground">No</td>
                      <td className="px-4 py-2 text-muted-foreground">Continue a previous conversation</td>
                    </tr>
                    <tr className="border-b border-border">
                      <td className="px-4 py-2 font-mono text-xs">context</td>
                      <td className="px-4 py-2 text-muted-foreground">object</td>
                      <td className="px-4 py-2 text-muted-foreground">No</td>
                      <td className="px-4 py-2 text-muted-foreground">Additional context for the agent</td>
                    </tr>
                    <tr>
                      <td className="px-4 py-2 font-mono text-xs">model</td>
                      <td className="px-4 py-2 text-muted-foreground">string</td>
                      <td className="px-4 py-2 text-muted-foreground">No</td>
                      <td className="px-4 py-2 text-muted-foreground">Override the default AI model</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div>
              <h3 className="font-medium text-foreground flex items-center gap-2 mb-3">
                <span className="text-xs font-mono bg-blue-100 text-blue-700 px-2 py-0.5 rounded">GET</span>
                <code className="text-sm font-mono">/health</code>
              </h3>
              <p className="text-muted-foreground text-sm">Health check endpoint. Returns <code className="text-xs font-mono bg-muted px-1 py-0.5 rounded">{"{ \"status\": \"ok\" }"}</code>.</p>
            </div>
          </div>
        </Section>

        <Section title="Multi-turn Conversations" icon={MessageSquare}>
          <p className="text-muted-foreground text-sm leading-relaxed">
            The gateway supports multi-turn conversations. On the first request, omit the{" "}
            <code className="text-xs font-mono bg-muted px-1 py-0.5 rounded">conversation_id</code> — the
            response will include one. Pass it back on subsequent requests to continue the conversation.
          </p>
          <CodeBlock>{`// First message — start a new conversation
{
  "message": "Show me my most recent contacts"
}

// Follow-up — continue the same conversation
{
  "message": "Tag the first one as VIP",
  "conversation_id": "55a5854d5c185c8b..."
}`}</CodeBlock>
        </Section>

        <Section title="Best Practices" icon={Shield}>
          <ul className="space-y-3 text-sm text-muted-foreground">
            <li className="flex items-start gap-2">
              <ArrowRight className="h-4 w-4 text-primary mt-0.5 shrink-0" />
              <span><strong className="text-foreground">Create a dedicated Ontraport user</strong> with restricted
              permissions specifically for agent use. Never use your admin API key.</span>
            </li>
            <li className="flex items-start gap-2">
              <ArrowRight className="h-4 w-4 text-primary mt-0.5 shrink-0" />
              <span><strong className="text-foreground">Start with read-only operations</strong> to understand how the
              agent interprets your requests before granting write access.</span>
            </li>
            <li className="flex items-start gap-2">
              <ArrowRight className="h-4 w-4 text-primary mt-0.5 shrink-0" />
              <span><strong className="text-foreground">Be specific in your messages.</strong> Instead of "clean up my
              contacts," say "list contacts with no email address added in the last 30 days."</span>
            </li>
            <li className="flex items-start gap-2">
              <ArrowRight className="h-4 w-4 text-primary mt-0.5 shrink-0" />
              <span><strong className="text-foreground">Review actions taken</strong> in each response. The{" "}
              <code className="text-xs font-mono bg-muted px-1 py-0.5 rounded">actions_taken</code> field
              shows exactly which Ontraport operations the agent performed.</span>
            </li>
            <li className="flex items-start gap-2">
              <ArrowRight className="h-4 w-4 text-primary mt-0.5 shrink-0" />
              <span><strong className="text-foreground">Monitor your usage.</strong> Each response includes token
              counts so you can track costs and identify unexpectedly complex requests.</span>
            </li>
          </ul>
        </Section>

        <div className="rounded-lg border border-border bg-card p-6 flex items-center justify-between">
          <div className="flex items-center gap-3">
            <FlaskConical className="h-5 w-5 text-primary" />
            <div>
              <h3 className="font-medium text-foreground">Try it out</h3>
              <p className="text-sm text-muted-foreground">Test the agent with your own API keys in the browser.</p>
            </div>
          </div>
          <Link
            href="/test"
            className="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-primary text-primary-foreground text-sm font-medium hover:opacity-90 transition-opacity"
          >
            Open Test Console
            <ArrowRight className="h-4 w-4" />
          </Link>
        </div>

        <footer className="pt-6 border-t border-border text-center text-xs text-muted-foreground">
          <p>Ontraport Agent Gateway &middot; Beta &middot; Use responsibly</p>
        </footer>
      </div>
    </div>
  );
}
