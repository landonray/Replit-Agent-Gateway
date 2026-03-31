import { useState, useRef, useEffect } from "react";
import { ArrowLeft, Send, Loader2, X, KeyRound, Wrench } from "lucide-react";
import { Link } from "wouter";
import ReactMarkdown from "react-markdown";

interface ToolCallResult {
  tool: string;
  parameters: Record<string, unknown>;
  success: boolean;
  result?: unknown;
  error?: string;
  blocked_by?: string;
  deduplicated?: boolean;
}

interface Message {
  role: "user" | "assistant" | "error" | "system";
  content: string;
  toolCalls?: ToolCallResult[];
  usage?: { input_tokens: number; output_tokens: number };
}

export default function TestChat() {
  const [apiKey, setApiKey] = useState("82td6Nd8G4cZtz0");
  const [appId, setAppId] = useState("2_270796_TuspM8iWE");
  const [message, setMessage] = useState("");
  const [messages, setMessages] = useState<Message[]>([]);
  const [conversationId, setConversationId] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLTextAreaElement>(null);

  const hasCredentials = apiKey.trim() !== "" && appId.trim() !== "";

  useEffect(() => {
    messagesEndRef.current?.scrollIntoView({ behavior: "smooth" });
  }, [messages]);

  async function sendMessage() {
    if (!message.trim() || !hasCredentials || loading) return;

    const userMessage = message.trim();
    setMessage("");
    setMessages((prev) => [...prev, { role: "user", content: userMessage }]);
    setLoading(true);

    try {
      const body: Record<string, string> = { message: userMessage };
      if (conversationId) {
        body.conversation_id = conversationId;
      }

      const res = await fetch(`${window.location.origin}/api/v1/agent`, {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "Api-Key": apiKey,
          "Api-Appid": appId,
        },
        body: JSON.stringify(body),
      });

      const text = await res.text();
      let data: Record<string, unknown>;
      try {
        data = JSON.parse(text);
      } catch {
        setMessages((prev) => [
          ...prev,
          { role: "error", content: `Invalid response from server:\n\n${text.slice(0, 1000)}` },
        ]);
        return;
      }

      if (!res.ok) {
        setMessages((prev) => [
          ...prev,
          { role: "error", content: (data.error as string) || `Error ${res.status}: ${text.slice(0, 500)}` },
        ]);
      } else {
        if (data.conversation_id) {
          setConversationId(data.conversation_id as string);
        }
        const content = (data.response as string) ?? `(No response text)\n\nRaw:\n${JSON.stringify(data, null, 2)}`;
        setMessages((prev) => [
          ...prev,
          {
            role: "assistant",
            content,
            toolCalls: (data.tool_calls as ToolCallResult[]) || [],
            usage: data.usage as { input_tokens: number; output_tokens: number },
          },
        ]);
      }
    } catch (err) {
      setMessages((prev) => [
        ...prev,
        {
          role: "error",
          content: err instanceof Error ? err.message : "Network error",
        },
      ]);
    } finally {
      setLoading(false);
      inputRef.current?.focus();
    }
  }

  const [loadingTools, setLoadingTools] = useState(false);

  async function fetchTools() {
    if (!hasCredentials || loadingTools) return;
    setLoadingTools(true);
    try {
      const res = await fetch(`${window.location.origin}/api/v1/tools`, {
        headers: {
          "Api-Key": apiKey,
          "Api-Appid": appId,
        },
      });
      const data = await res.json();
      if (!res.ok) {
        setMessages((prev) => [
          ...prev,
          { role: "error", content: data.error || `Error ${res.status}` },
        ]);
      } else if (!data.tool_count) {
        setMessages((prev) => [
          ...prev,
          {
            role: "system",
            content: `0 tools returned. Full response:\n\n${JSON.stringify(data, null, 2)}`,
          },
        ]);
      } else {
        const toolList = (data.tools || [])
          .map((t: { name: string; description: string; parameters: string[] }) =>
            `${t.name} — ${t.description}\n  params: ${t.parameters.join(", ") || "none"}`
          )
          .join("\n\n");
        setMessages((prev) => [
          ...prev,
          {
            role: "system",
            content: `${data.tool_count} tools available:\n\n${toolList}`,
          },
        ]);
      }
    } catch (err) {
      setMessages((prev) => [
        ...prev,
        { role: "error", content: err instanceof Error ? err.message : "Network error" },
      ]);
    } finally {
      setLoadingTools(false);
    }
  }

  function clearChat() {
    setMessages([]);
    setConversationId(null);
  }

  function handleKeyDown(e: React.KeyboardEvent) {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  }

  return (
    <div className="min-h-screen bg-background flex flex-col">
      <header className="border-b border-border bg-card px-6 py-3 flex items-center justify-between shrink-0">
        <div className="flex items-center gap-3">
          <Link href="/" className="text-muted-foreground hover:text-foreground transition-colors">
            <ArrowLeft className="h-5 w-5" />
          </Link>
          <h1 className="text-lg font-semibold text-foreground">Agent Test Console</h1>
          {conversationId && (
            <span className="text-xs font-mono text-muted-foreground bg-muted px-2 py-0.5 rounded">
              {conversationId.slice(0, 12)}...
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={fetchTools}
            disabled={!hasCredentials || loadingTools}
            className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1 px-2 py-1 rounded hover:bg-muted transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {loadingTools ? <Loader2 className="h-3 w-3 animate-spin" /> : <Wrench className="h-3 w-3" />}
            View Tools
          </button>
          {messages.length > 0 && (
            <button
              onClick={clearChat}
              className="text-xs text-muted-foreground hover:text-foreground flex items-center gap-1 px-2 py-1 rounded hover:bg-muted transition-colors"
            >
              <X className="h-3 w-3" />
              Clear
            </button>
          )}
        </div>
      </header>

      <div className="border-b border-border bg-muted/30 px-6 py-3 shrink-0">
        <div className="max-w-3xl mx-auto flex items-center gap-4">
          <KeyRound className="h-4 w-4 text-muted-foreground shrink-0" />
          <div className="flex gap-3 flex-1">
            <input
              type="password"
              placeholder="Api-Key"
              value={apiKey}
              onChange={(e) => setApiKey(e.target.value)}
              className="flex-1 text-sm px-3 py-1.5 rounded-md border border-input bg-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring font-mono"
              autoComplete="off"
            />
            <input
              type="password"
              placeholder="Api-Appid"
              value={appId}
              onChange={(e) => setAppId(e.target.value)}
              className="w-40 text-sm px-3 py-1.5 rounded-md border border-input bg-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring font-mono"
              autoComplete="off"
            />
          </div>
          <span className="text-xs text-muted-foreground whitespace-nowrap">Not saved</span>
        </div>
      </div>

      <div className="flex-1 overflow-y-auto">
        <div className="max-w-3xl mx-auto px-6 py-6 space-y-4">
          {messages.length === 0 && (
            <div className="text-center py-20 space-y-3">
              <p className="text-muted-foreground text-sm">
                Enter your Ontraport API credentials above and send a message to test the agent.
              </p>
              <p className="text-muted-foreground text-xs">
                Credentials are only held in memory — they are never saved or stored.
              </p>
            </div>
          )}

          {messages.map((msg, i) => (
            <div key={i} className={`flex ${msg.role === "user" ? "justify-end" : "justify-start"}`}>
              <div
                className={`max-w-[85%] rounded-lg px-4 py-3 text-sm leading-relaxed ${
                  msg.role === "user"
                    ? "bg-primary text-primary-foreground"
                    : msg.role === "error"
                    ? "bg-red-50 border border-red-200 text-red-700"
                    : msg.role === "system"
                    ? "bg-slate-100 border border-slate-300 text-slate-700 font-mono text-xs"
                    : "bg-card border border-border text-card-foreground"
                }`}
              >
                {msg.role === "assistant" ? (
                  <div className="prose prose-sm max-w-none prose-p:my-1 prose-headings:my-2 prose-ul:my-1 prose-ol:my-1 prose-li:my-0.5 prose-pre:my-2 prose-code:bg-muted prose-code:px-1 prose-code:py-0.5 prose-code:rounded prose-code:text-xs">
                    <ReactMarkdown>{msg.content}</ReactMarkdown>
                  </div>
                ) : (
                  <p className="whitespace-pre-wrap">{msg.content}</p>
                )}
                {msg.toolCalls && msg.toolCalls.length > 0 && (
                  <div className="mt-2 pt-2 border-t border-border/50 space-y-1">
                    {msg.toolCalls.map((tc, j) => (
                      <div
                        key={j}
                        className={`text-xs font-mono px-2 py-1.5 rounded ${
                          tc.success
                            ? tc.deduplicated
                              ? "bg-yellow-50 text-yellow-700 border border-yellow-200"
                              : "bg-green-50 text-green-700 border border-green-200"
                            : "bg-red-50 text-red-700 border border-red-200"
                        }`}
                      >
                        <div className="flex items-center gap-1.5">
                          <span>{tc.success ? (tc.deduplicated ? "↩" : "✓") : "✗"}</span>
                          <span className="font-semibold">{tc.tool}</span>
                          {tc.blocked_by && <span className="text-red-500">({tc.blocked_by})</span>}
                          {tc.deduplicated && <span className="text-yellow-600">(cached)</span>}
                        </div>
                        {!tc.success && tc.parameters && Object.keys(tc.parameters).length > 0 && (
                          <p className="mt-1 break-words whitespace-pre-wrap opacity-60">
                            Submitted: {JSON.stringify(tc.parameters)}
                          </p>
                        )}
                        {tc.error && (
                          <p className="mt-1 break-words whitespace-pre-wrap opacity-80">— {tc.error}</p>
                        )}
                      </div>
                    ))}
                  </div>
                )}
                {msg.usage && (
                  <div className="mt-1 text-xs text-muted-foreground">
                    {msg.usage.input_tokens} in / {msg.usage.output_tokens} out tokens
                  </div>
                )}
              </div>
            </div>
          ))}

          {loading && (
            <div className="flex justify-start">
              <div className="bg-card border border-border rounded-lg px-4 py-3 flex items-center gap-2 text-muted-foreground text-sm">
                <Loader2 className="h-4 w-4 animate-spin" />
                Thinking...
              </div>
            </div>
          )}

          <div ref={messagesEndRef} />
        </div>
      </div>

      <div className="border-t border-border bg-card px-6 py-3 shrink-0">
        <div className="max-w-3xl mx-auto flex gap-2">
          <textarea
            ref={inputRef}
            value={message}
            onChange={(e) => setMessage(e.target.value)}
            onKeyDown={handleKeyDown}
            placeholder={hasCredentials ? "Type a message..." : "Enter API credentials above first"}
            disabled={!hasCredentials || loading}
            rows={1}
            className="flex-1 text-sm px-3 py-2 rounded-md border border-input bg-background placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring resize-none disabled:opacity-50"
          />
          <button
            onClick={sendMessage}
            disabled={!message.trim() || !hasCredentials || loading}
            className="px-3 py-2 rounded-md bg-primary text-primary-foreground hover:opacity-90 transition-opacity disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <Send className="h-4 w-4" />
          </button>
        </div>
      </div>
    </div>
  );
}
